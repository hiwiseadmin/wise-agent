<?php
declare(strict_types=1);

namespace wise\agent\config;

use think\App;
use wise\agent\bridge\ProviderCache;
use wise\support\config\CheckLevel;
use wise\support\config\CheckResult;
use wise\support\config\ConfigCheckInterface;

/**
 * wise-agent 提供者缓存有效性检查
 *
 * 检测 AI Provider 缓存是否因插件安装/卸载而失效。
 * 失效时需要运行 php think wise:agent:discover 重建缓存。
 *
 * 级别：WARNING（缓存失效不会导致系统崩溃，仅影响 AI 工具发现）
 */
class AgentCacheCheck implements ConfigCheckInterface
{
    public function __construct(
        private readonly App $app,
    ) {
    }

    public function id(): string
    {
        return 'wise-agent:provider-cache';
    }

    public function package(): string
    {
        return 'wise-agent';
    }

    public function level(): CheckLevel
    {
        return CheckLevel::WARNING;
    }

    public function check(): CheckResult
    {
        $cache = new ProviderCache($this->app);

        if ($cache->isInvalidated()) {
            return CheckResult::fail(
                'AI provider cache is invalidated',
                'Run: php think wise:agent:discover'
            );
        }

        return CheckResult::ok();
    }
}
