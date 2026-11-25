<?php
declare(strict_types=1);

namespace wise\agent\config;

use wise\support\config\CheckLevel;
use wise\support\config\CheckResult;
use wise\support\config\ConfigCheckInterface;

/**
 * wise-agent AI API Key 检测
 *
 * 检测当前默认 provider 的 api_key 是否配置。
 */
class AgentSecretCheck implements ConfigCheckInterface
{
    public function __construct(
        private readonly \think\Config $config,
    ) {
    }

    public function id(): string
    {
        return 'wise-agent:secret';
    }

    public function package(): string
    {
        return 'wise-agent';
    }

    public function level(): CheckLevel
    {
        return CheckLevel::ERROR;
    }

    public function check(): CheckResult
    {
        $defaultProvider = (string) $this->config->get('wise-agent.default', 'openai');
        $providers = (array) $this->config->get('wise-agent.providers', []);
        $warnings = [];

        foreach ($providers as $name => $cfg) {
            if (!is_array($cfg)) {
                continue;
            }

            $apiKey = (string) ($cfg['api_key'] ?? '');

            // 跳过未配置且非默认的 provider
            if ($apiKey === '' && $name !== $defaultProvider) {
                continue;
            }

            if ($apiKey === '') {
                $envVar = strtoupper($name) . '_API_KEY';
                $warnings[] = "$name provider 的 $envVar 未配置";
            }
        }

        if (!empty($warnings)) {
            return CheckResult::fail(
                'AI Provider API Key 缺失: ' . implode('; ', $warnings),
                '请在 .env 中设置对应 provider 的 API_KEY'
            );
        }

        return CheckResult::ok();
    }
}
