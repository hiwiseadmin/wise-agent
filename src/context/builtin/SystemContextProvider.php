<?php
declare(strict_types=1);

namespace wise\agent\context\builtin;

use wise\agent\agent\AgentContext;
use wise\agent\context\contract\ContextProviderInterface;

/**
 * 系统信息上下文注入器
 *
 * 向 AI 注入当前系统环境信息：PHP 版本、框架版本、当前时间等。
 * 优先级较低（0），不抢占关键上下文位置。
 */
class SystemContextProvider implements ContextProviderInterface
{
    public function getPriority(): int
    {
        return 0;
    }

    public function getContext(AgentContext $agentContext): array
    {
        $info = [
            '当前时间' => date('Y-m-d H:i:s'),
            '时区'     => date_default_timezone_get(),
            'PHP 版本' => PHP_VERSION,
            '操作系统' => PHP_OS,
        ];

        if (defined('\think\App::VERSION')) {
            $info['框架版本'] = \think\App::VERSION;
        }

        $lines = [];
        foreach ($info as $key => $value) {
            $lines[] = "  - {$key}: {$value}";
        }

        return [[
            'role'    => 'system',
            'content' => "【系统环境信息】\n" . implode("\n", $lines),
        ]];
    }

    public function getSource(): string
    {
        return 'builtin:system';
    }
}
