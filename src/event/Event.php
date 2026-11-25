<?php
declare(strict_types=1);

namespace wise\agent\event;

/**
 * 基础事件类
 *
 * 所有 AI 生命周期事件均继承此类，
 * 以提供统一的时间戳和身份契约。
 *
 * @property-read float $timestamp 事件创建时的微时间
 */
abstract class Event
{
    /** @var float 事件创建时的微时间 */
    public float $timestamp;

    public function __construct()
    {
        $this->timestamp = microtime(true);
    }

    /**
     * 获取事件名称，用于监听器路由
     */
    abstract public function getEventName(): string;
}
