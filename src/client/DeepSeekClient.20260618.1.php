<?php
declare(strict_types=1);

namespace wise\agent\Client;

use wise\agent\Exception\ProviderException;

/**
 * DeepSeek API client
 *
 * DeepSeek uses an OpenAI-compatible API format.
 * Inherits chatStream() from OpenAiClient with DeepSeek-specific
 * default configuration.
 */
class DeepSeekClient extends OpenAiClient
{
    public function __construct(array $config = [])
    {
        // Apply DeepSeek defaults
        $config['base_url'] = $config['base_url'] ?? 'https://api.deepseek.com/v1';
        $config['model']    = $config['model'] ?? 'deepseek-chat';

        parent::__construct($config);
    }

    public function getProviderName(): string
    {
        return 'deepseek';
    }

    public function supports(string $feature): bool
    {
        // DeepSeek does not support JSON mode
        if ($feature === Feature::JSON_MODE) {
            return false;
        }
        return parent::supports($feature);
    }
}
