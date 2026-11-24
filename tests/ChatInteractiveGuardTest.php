<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use wise\agent\command\Chat;

/**
 * 交互模式拦截（Windows 控制台）测试
 *
 * 背景：PowerShell 5.1 / 7、conhost、部分 IDE 终端都会在把中文交给 `fgets()` 之前
 * 把字节转坏（变成无关字符或空内容），PHP 侧无法还原。命令因此在
 * 「Windows + 真实控制台」下默认跳过交互模式，改给参数 / --file / 管道三种替代用法。
 */
class ChatInteractiveGuardTest extends TestCase
{
    private function blocker(?bool $isTty): string
    {
        $method = new \ReflectionMethod(Chat::class, 'interactiveBlocker');
        $method->setAccessible(true);

        return (string) $method->invoke(new Chat(), $isTty);
    }

    public function testPipeInputIsNotBlocked(): void
    {
        $this->assertSame('', $this->blocker(false));
    }

    public function testWindowsConsoleIsBlockedWithActionableHint(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('仅 Windows 控制台需要拦截');
        }

        $hint = $this->blocker(true);

        $this->assertNotSame('', $hint);
        $this->assertStringContainsString('--file=question.txt', $hint);
        $this->assertStringContainsString('--interactive', $hint);
    }
}
