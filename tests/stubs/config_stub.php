<?php
declare(strict_types=1);

/**
 * 离线测试用 config() 替身
 *
 * wise-agent 的部分安全类会读取 wise-agent.tools 配置；离线单测没有
 * ThinkPHP 容器，这里返回调用方给出的默认值，等价于“未配置”。
 */
if (!function_exists('config')) {
    function config($key = null, $default = null)
    {
        return $default;
    }
}
