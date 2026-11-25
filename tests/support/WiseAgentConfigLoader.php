<?php
declare(strict_types=1);

/**
 * 测试支撑：加载 wise-agent 的**出厂配置文件**
 *
 * 为什么需要：`config/wise-agent.php` 用了 ThinkPHP 的全局助手 `env()`，
 * 而本包的单测环境只有 composer autoload（框架的 `helper.php` 不随自动加载进入），
 * 因此这里在**全局命名空间**提供一个最小桩（仅在该函数未定义时声明）。
 *
 * 用途：断言「出厂默认值」本身是安全的（总开关关闭、写盘 never、白名单空、
 * 锚点权限开启 …）—— 这类默认值一旦被改错，等价于把 AI 的能力面悄悄放大。
 *
 * ⚠️ 本文件必须保持**全局命名空间**（不能加 `namespace`）。
 */

if (!function_exists('env')) {
    function env(?string $name = null, mixed $default = null): mixed
    {
        return $default;
    }
}

if (!function_exists('wise_agent_test_config')) {
    /**
     * @return array<string, mixed>
     */
    function wise_agent_test_config(): array
    {
        $config = require dirname(__DIR__, 2) . '/config/wise-agent.php';

        return $config;
    }
}
