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
    /** @var string[] DeepSeek 可用模型 */
    protected const DEFAULT_MODELS = [
        'deepseek-chat',
        'deepseek-reasoner',
    ];

    /**
     * DeepSeek 价目（美元 / 每 1M token）
     *
     * 与 OpenAI 差距很大，必须独立配置；沿用父类价目表会高估成本一个量级。
     *
     * @var array<string, array{input:float, output:float}>
     */
    protected const DEFAULT_PRICING = [
        'deepseek-chat'     => ['input' => 0.27, 'output' => 1.10],
        'deepseek-reasoner' => ['input' => 0.55, 'output' => 2.19],
    ];

    public function __construct(array $config = [])
    {
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
        if ($feature === Feature::JSON_MODE) {
            return false;
        }
        return parent::supports($feature);
    }
}
