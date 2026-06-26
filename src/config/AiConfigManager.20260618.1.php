<?php
declare(strict_types=1);

namespace wise\agent\config;

use think\facade\Db;
use think\facade\Log;

/**
 * AI Configuration Manager
 *
 * Manages AI provider configuration with dual-mode storage:
 *   1. DB mode — reads/writes to ws_ai_config table (runtime configurable)
 *   2. .env mode — falls back to environment variables when DB is unavailable
 *
 * API Keys are encrypted with AES-256-CBC before storage.
 * The encryption key is read from AI_ENCRYPT_KEY in .env.
 */
class AiConfigManager
{
    protected string $table = 'ws_ai_config';
    protected string $encryptKey;
    protected string $cipher = 'AES-256-CBC';
    protected bool $useDb = true;

    /**
     * @param string|null $encryptKey Encryption key (reads from AI_ENCRYPT_KEY env if null)
     */
    public function __construct(?string $encryptKey = null)
    {
        $this->encryptKey = $encryptKey ?? env('AI_ENCRYPT_KEY', 'wiseadmin_default_encryption_key_32b');
    }

    /**
     * Get configuration for a specific provider
     *
     * @param string $provider Provider name (openai, deepseek, etc.)
     * @return array|null Configuration array or null if not found
     */
    public function get(string $provider): ?array
    {
        if ($this->useDb) {
            try {
                $row = Db::table($this->table)
                    ->where('provider', $provider)
                    ->where('is_active', 1)
                    ->find();

                if ($row) {
                    return $this->rowToConfig($row);
                }
            } catch (\Throwable $e) {
                Log::warning("[AiConfigManager] DB read failed for '{$provider}': {$e->getMessage()}");
                $this->useDb = false;
            }
        }

        // Fallback to .env mode
        return $this->getFromEnv($provider);
    }

    /**
     * Save provider configuration
     *
     * @param string $provider Provider name
     * @param array  $config   Configuration [api_key, api_base, model, max_tokens, temperature, is_active]
     * @return bool
     */
    public function save(string $provider, array $config): bool
    {
        $data = [
            'provider'    => $provider,
            'api_base'    => $config['api_base'] ?? '',
            'model'       => $config['model'] ?? '',
            'max_tokens'  => (int) ($config['max_tokens'] ?? 4096),
            'temperature' => (float) ($config['temperature'] ?? 0.7),
            'is_active'   => (int) ($config['is_active'] ?? 1),
            'update_time'  => date('Y-m-d H:i:s'),
        ];

        // Encrypt API key if provided
        if (isset($config['api_key']) && !empty($config['api_key'])) {
            $data['api_key'] = $this->encrypt($config['api_key']);
        }

        try {
            $existing = Db::table($this->table)
                ->where('provider', $provider)
                ->find();

            if ($existing) {
                Db::table($this->table)
                    ->where('provider', $provider)
                    ->update($data);
            } else {
                $data['create_time'] = date('Y-m-d H:i:s');
                Db::table($this->table)->insert($data);
            }

            return true;
        } catch (\Throwable $e) {
            Log::error("[AiConfigManager] DB save failed for '{$provider}': {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Get all provider configurations
     *
     * @return array List of provider configs (with api_key masked)
     */
    public function getAll(): array
    {
        $configs = [];

        if ($this->useDb) {
            try {
                $rows = Db::table($this->table)->select()->toArray();
                foreach ($rows as $row) {
                    $configs[] = $this->rowToConfig($row, true);
                }
            } catch (\Throwable $e) {
                Log::warning("[AiConfigManager] DB getAll failed: {$e->getMessage()}");
            }
        }

        // Also include providers from .env if not already fetched from DB
        $envProviders = ['openai', 'deepseek'];
        foreach ($envProviders as $provider) {
            $found = false;
            foreach ($configs as $c) {
                if (($c['provider'] ?? '') === $provider) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $envConfig = $this->getFromEnv($provider);
                if ($envConfig) {
                    $configs[] = $envConfig;
                }
            }
        }

        return $configs;
    }

    /**
     * Delete a provider configuration
     *
     * @param string $provider Provider name
     * @return bool
     */
    public function delete(string $provider): bool
    {
        try {
            Db::table($this->table)->where('provider', $provider)->delete();
            return true;
        } catch (\Throwable $e) {
            Log::error("[AiConfigManager] DB delete failed for '{$provider}': {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Set a provider as active/inactive
     *
     * @param string $provider Provider name
     * @param bool   $active   Active status
     * @return bool
     */
    public function setActive(string $provider, bool $active): bool
    {
        try {
            Db::table($this->table)
                ->where('provider', $provider)
                ->update([
                    'is_active'  => $active ? 1 : 0,
                    'update_time' => date('Y-m-d H:i:s'),
                ]);
            return true;
        } catch (\Throwable $e) {
            Log::error("[AiConfigManager] DB setActive failed for '{$provider}': {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Convert a DB row to a config array
     *
     * @param array $row       Database row
     * @param bool  $maskKey   Whether to mask the API key for display
     * @return array
     */
    protected function rowToConfig(array $row, bool $maskKey = false): array
    {
        $apiKey = '';
        if (!empty($row['api_key'])) {
            try {
                $apiKey = $this->decrypt($row['api_key']);
            } catch (\Throwable $e) {
                $apiKey = '';
            }
        }

        if ($maskKey && !empty($apiKey)) {
            $apiKey = $this->maskApiKey($apiKey);
        }

        return [
            'provider'    => $row['provider'] ?? '',
            'api_key'     => $apiKey,
            'api_base'    => $row['api_base'] ?? '',
            'model'       => $row['model'] ?? '',
            'max_tokens'  => (int) ($row['max_tokens'] ?? 4096),
            'temperature' => (float) ($row['temperature'] ?? 0.7),
            'is_active'   => (int) ($row['is_active'] ?? 1),
        ];
    }

    /**
     * Get config from .env fallback
     *
     * @param string $provider Provider name
     * @return array|null
     */
    protected function getFromEnv(string $provider): ?array
    {
        $envMap = [
            'openai' => [
                'api_key'    => env('OPENAI_API_KEY', ''),
                'api_base'   => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
                'model'      => env('OPENAI_MODEL', 'gpt-4o'),
            ],
            'deepseek' => [
                'api_key'    => env('DEEPSEEK_API_KEY', ''),
                'api_base'   => env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com/v1'),
                'model'      => env('DEEPSEEK_MODEL', 'deepseek-chat'),
            ],
        ];

        if (!isset($envMap[$provider])) {
            return null;
        }

        $config = $envMap[$provider];
        $config['provider'] = $provider;
        $config['max_tokens'] = 4096;
        $config['temperature'] = 0.7;
        $config['is_active'] = 1;

        return $config;
    }

    /**
     * Mask an API key for safe display
     *
     * Shows first 4 and last 4 characters, masks the rest.
     *
     * @param string $apiKey Full API key
     * @return string Masked key (e.g., "sk-a...b1c2")
     */
    public function maskApiKey(string $apiKey): string
    {
        $len = strlen($apiKey);
        if ($len <= 8) {
            return str_repeat('*', $len);
        }
        return substr($apiKey, 0, 4) . str_repeat('*', min($len - 8, 12)) . substr($apiKey, -4);
    }

    /**
     * Encrypt data with AES-256-CBC
     *
     * @param string $data Plain text data
     * @return string Base64-encoded encrypted data (IV prepended)
     */
    public function encrypt(string $data): string
    {
        $key = $this->deriveKey();
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($data, $this->cipher, $key, OPENSSL_RAW_DATA, $iv);

        if ($encrypted === false) {
            throw new \RuntimeException('Encryption failed: ' . openssl_error_string());
        }

        // Prepend IV to encrypted data, then base64 encode
        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt data with AES-256-CBC
     *
     * @param string $data Base64-encoded encrypted data (IV prepended)
     * @return string Plain text data
     */
    public function decrypt(string $data): string
    {
        $key = $this->deriveKey();
        $decoded = base64_decode($data, true);

        if ($decoded === false || strlen($decoded) < 16) {
            throw new \RuntimeException('Invalid encrypted data');
        }

        $iv = substr($decoded, 0, 16);
        $encrypted = substr($decoded, 16);

        $decrypted = openssl_decrypt($encrypted, $this->cipher, $key, OPENSSL_RAW_DATA, $iv);

        if ($decrypted === false) {
            throw new \RuntimeException('Decryption failed: ' . openssl_error_string());
        }

        return $decrypted;
    }

    /**
     * Derive a 32-byte encryption key from the configured key
     *
     * Ensures the key is exactly 32 bytes for AES-256-CBC.
     *
     * @return string 32-byte binary key
     */
    protected function deriveKey(): string
    {
        $key = $this->encryptKey;

        // If the key is already 32 bytes, use as-is
        if (strlen($key) === 32) {
            return $key;
        }

        // Use SHA-256 to derive a consistent 32-byte key
        return hash('sha256', $key, true);
    }

    /**
     * Check if the config table exists and is accessible
     *
     * @return bool
     */
    public function isDbAvailable(): bool
    {
        try {
            Db::query("SELECT 1 FROM `{$this->table}` LIMIT 1");
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
