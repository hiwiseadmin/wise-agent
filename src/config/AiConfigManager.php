<?php
declare(strict_types=1);

namespace wise\agent\config;

use think\facade\Db;
use wise\support\context\ContextualLogger;

/**
 * AI 配置管理器
 *
 * 通过双模式存储管理 AI Provider 配置：
 *   1. DB 模式 — 读写 ws_ai_config 表（运行时配置）
 *   2. .env 模式 — 当 DB 不可用时，回退到环境变量
 *
 * API Key 在存储前使用 AES-256-CBC 加密。
 * 加密密钥从 .env 中的 AI_ENCRYPT_KEY 读取。
 */
class AiConfigManager
{
    protected string $table;
    protected string $encryptKey;
    // GCM 模式提供内置认证（HMAC），防止密文篡改；PHP 7.1+ 支持
    protected string $cipher = 'AES-256-GCM';
    protected int $tagLength = 16;
    protected bool $useDb = true;

    /**
     * @param string|null $encryptKey 加密密钥（为 null 时从 AI_ENCRYPT_KEY 环境变量读取）
     */
    public function __construct(?string $encryptKey = null)
    {
        $this->encryptKey = $encryptKey ?? env('AI_ENCRYPT_KEY', '');
        $this->table = 'ai_config';
    }

    /**
     * 获取指定 Provider 的配置
     *
     * @param string $provider Provider 名称（openai、deepseek 等）
     * @return array|null 配置数组，未找到则返回 null
     */
    public function get(string $provider): ?array
    {
        if ($this->useDb) {
            try {
                $row = Db::name($this->table)
                    ->where('provider', $provider)
                    ->where('is_active', 1)
                    ->find();

                if ($row) {
                    return $this->rowToConfig($row);
                }
            } catch (\Throwable $e) {
                ContextualLogger::warning("[AiConfigManager] DB read failed for '{$provider}': {$e->getMessage()}");
                $this->useDb = false;
            }
        }

        // 回退到 .env 模式
        return $this->getFromEnv($provider);
    }

    /**
     * 保存 Provider 配置
     *
     * @param string $provider Provider 名称
     * @param array  $config   配置 [api_key, api_base, model, max_tokens, temperature, is_active]
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

        // 如果提供了 API key，则加密
        if (isset($config['api_key']) && !empty($config['api_key'])) {
            $data['api_key'] = $this->encrypt($config['api_key']);
        }

        try {
            $existing = Db::name($this->table)
                ->where('provider', $provider)
                ->find();

            if ($existing) {
                Db::name($this->table)
                    ->where('provider', $provider)
                    ->update($data);
            } else {
                $data['create_time'] = date('Y-m-d H:i:s');
                Db::name($this->table)->insert($data);
            }

            return true;
        } catch (\Throwable $e) {
            ContextualLogger::error("[AiConfigManager] DB save failed for '{$provider}': {$e->getMessage()}");
            return false;
        }
    }

    /**
     * 获取所有 Provider 配置
     *
     * @return array Provider 配置列表（api_key 已脱敏）
     */
    public function getAll(): array
    {
        $configs = [];

        if ($this->useDb) {
            try {
                $rows = Db::name($this->table)->select()->toArray();
                foreach ($rows as $row) {
                    $configs[] = $this->rowToConfig($row, true);
                }
            } catch (\Throwable $e) {
                ContextualLogger::warning("[AiConfigManager] DB getAll failed: {$e->getMessage()}");
            }
        }

        // 同时包含来自 .env 但尚未从 DB 获取的 Provider
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
                    // 脱敏 env 回退中的 API key，与 DB 路径保持一致
                    if (!empty($envConfig['api_key'])) {
                        $envConfig['api_key'] = $this->maskApiKey($envConfig['api_key']);
                    }
                    $configs[] = $envConfig;
                }
            }
        }

        return $configs;
    }

    /**
     * 删除一个 Provider 配置
     *
     * @param string $provider Provider 名称
     * @return bool
     */
    public function delete(string $provider): bool
    {
        try {
            Db::name($this->table)->where('provider', $provider)->delete();
            return true;
        } catch (\Throwable $e) {
            ContextualLogger::error("[AiConfigManager] DB delete failed for '{$provider}': {$e->getMessage()}");
            return false;
        }
    }

    /**
     * 设置 Provider 的启用/停用状态
     *
     * @param string $provider Provider 名称
     * @param bool   $active   启用状态
     * @return bool
     */
    public function setActive(string $provider, bool $active): bool
    {
        try {
            Db::name($this->table)
                ->where('provider', $provider)
                ->update([
                    'is_active'  => $active ? 1 : 0,
                    'update_time' => date('Y-m-d H:i:s'),
                ]);
            return true;
        } catch (\Throwable $e) {
            ContextualLogger::error("[AiConfigManager] DB setActive failed for '{$provider}': {$e->getMessage()}");
            return false;
        }
    }

    /**
     * 将数据库行转换为配置数组
     *
     * @param array $row     数据库行
     * @param bool  $maskKey 是否对 API key 脱敏以便显示
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
     * 从 .env 回退获取配置
     *
     * @param string $provider Provider 名称
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
     * 对 API key 脱敏以便安全显示
     *
     * 显示前 4 位和后 4 位字符，中间部分用星号遮盖。
     *
     * @param string $apiKey 完整的 API key
     * @return string 脱敏后的 key（例如 "sk-a...b1c2"）
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
     * 使用 AES-256-GCM 加密数据（带内置认证）
     *
     * @param string $data 明文数据
     * @return string Base64 编码：IV(16) + TAG(16) + CIPHERTEXT
     */
    public function encrypt(string $data): string
    {
        $this->ensureKey();
        $key = $this->deriveKey();
        $iv = random_bytes(16);
        $tag = '';
        $encrypted = openssl_encrypt($data, $this->cipher, $key, OPENSSL_RAW_DATA, $iv, $tag, '', $this->tagLength);

        if ($encrypted === false) {
            throw new \RuntimeException('Encryption failed: ' . openssl_error_string());
        }

        // GCM 格式：IV(16) + TAG(16) + CIPHERTEXT
        return base64_encode($iv . $tag . $encrypted);
    }

    /**
     * 解密数据（自动适配 AES-256-GCM 和旧版 AES-256-CBC 格式）
     *
     * 数据格式检测：
     *   - GCM: IV(16) + TAG(16) + CIPHERTEXT → 至少 32 字节
     *   - CBC: IV(16) + CIPHERTEXT → 至少 16 字节
     *
     * @param string $data Base64 编码的加密数据
     * @return string 明文数据
     */
    public function decrypt(string $data): string
    {
        $this->ensureKey();
        $key = $this->deriveKey();
        $decoded = base64_decode($data, true);

        if ($decoded === false || strlen($decoded) < 16) {
            throw new \RuntimeException('Invalid encrypted data');
        }

        // 自动检测格式：GCM 格式至少 32 字节（16 IV + 16 TAG），且 IV 不在开头时尝试 CBC
        $isGcmFormat = strlen($decoded) >= 32;
        $decrypted = false;

        if ($isGcmFormat) {
            $iv  = substr($decoded, 0, 16);
            $tag = substr($decoded, 16, $this->tagLength);
            $encrypted = substr($decoded, 32);
            $decrypted = openssl_decrypt($encrypted, $this->cipher, $key, OPENSSL_RAW_DATA, $iv, $tag);
        }

        // GCM 失败时回退到旧版 CBC 格式（向后兼容迁移期数据）
        if ($decrypted === false) {
            $iv  = substr($decoded, 0, 16);
            $encrypted = substr($decoded, 16);
            $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

            if ($decrypted === false) {
                throw new \RuntimeException('Decryption failed: data is neither valid GCM nor CBC format');
            }
        }

        return $decrypted;
    }

    /**
     * 从配置的密钥派生出 32 字节的加密密钥
     *
     * 确保密钥恰好为 32 字节，以满足 AES-256-CBC 的要求。
     *
     * @return string 32 字节二进制密钥
     */
    protected function deriveKey(): string
    {
        $key = $this->encryptKey;

        // 使用 PBKDF2 派生安全的 32 字节密钥（带迭代拉伸，防止弱密钥被彩虹表攻击）
        // salt 结合应用固定标识与密钥本身，确保不同部署的派生密钥不互通
        $appSalt = hash('sha256', 'wise-agent-key-derivation-v2', true);
        $uniqueSalt = hash('sha256', $key . $appSalt, true);
        return hash_pbkdf2('sha256', $key, $uniqueSalt, 10000, 32, true);
    }

    /**
     * 确保加密密钥已配置（延迟校验，仅在 encrypt/decrypt 调用时抛出）
     */
    private function ensureKey(): void
    {
        if (empty($this->encryptKey)) {
            throw new \RuntimeException(
                'AI_ENCRYPT_KEY must be set in .env to encrypt API keys securely. '
                . 'Generate one with: php -r "echo bin2hex(random_bytes(32));"'
            );
        }
    }

    /**
     * 检查配置表是否存在且可访问
     *
     * @return bool
     */
    public function isDbAvailable(): bool
    {
        try {
            Db::name($this->table)->limit(1)->find();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
