<?php
declare(strict_types=1);

namespace wise\agent\client;

use wise\agent\exception\ProviderException;

/**
 * DeepSeek API 客户端
 *
 * DeepSeek 使用与 OpenAI 兼容的 API 格式。
 * 继承 OpenAiClient 的 chatStream()，使用 DeepSeek 特定的
 * 默认配置。
 */
class DeepSeekClient extends OpenAiClient
{
    public function __construct(array $config = [])
    {
        // 应用 DeepSeek 默认值
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
        // DeepSeek 不支持 JSON 模式
        if ($feature === Feature::JSON_MODE) {
            return false;
        }
        return parent::supports($feature);
    }
}
