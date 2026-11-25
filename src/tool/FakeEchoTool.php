<?php
declare(strict_types=1);

namespace wise\agent\tool;

use wise\agent\contract\ToolInterface;
use wise\agent\security\CapabilityAccess;

/**
 * 本地测试用 Echo 工具
 *
 * 实现 ToolInterface，仅回显传入的 text 参数，不依赖任何外部服务，
 * 用于验证 agent→tool 的工具分发与执行链路。
 */
class FakeEchoTool implements ToolInterface
{
    public function getName(): string
    {
        return 'echo_tool';
    }

    public function getDescription(): string
    {
        return 'Echo back the provided text. (Fake test tool, no side effects)';
    }

    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'text' => ['type' => 'string', 'description' => 'Text to echo back'],
            ],
            'required'   => ['text'],
        ];
    }

    public function execute(array $arguments): string
    {
        $text = $arguments['text'] ?? '';
        return 'Echo: ' . $text;
    }

    public function getPermission(): string
    {
        return '';
    }

    public function requireConfirmation(): bool
    {
        return false;
    }

    public function getAccess(): string
    {
        return CapabilityAccess::READ;
    }

    public function getRiskLevel(): string
    {
        return CapabilityAccess::RISK_LOW;
    }
}
