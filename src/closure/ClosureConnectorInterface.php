<?php
declare(strict_types=1);

namespace wise\agent\closure;

/**
 * 闭环连接器契约＼�
 *
 * 一个连接器 = 「把闭环事件投递到某个目的地」的一环（通知 / webhook / 审计 / 实例自定义）。
 *
 * ## 三条硬约定
 *
 * 1. **`dispatch()` 不得抛异常**：拿不到可用的下游（包未安装、配置缺失）时返回
 *    `ConnectorResult::skipped()` 或 `failed()`，**不要**让异常冒出去 ——
 *    虽然 {@see ClosureDispatcher} 也会兜住异常，但「自己报错」比「被兜住」信息更准。
 * 2. **不做重试**：重试一律下沉到下游（`wise-notification` 的 `SendNotificationJob`、
 *    `wise-webhook` 的 `CallWebhookJob`）；连接器内循环重试会与它们的退避策略打架。
 * 3. **不做授权判定**：连接器只负责投递；审批/RBAC 是既有链路的事＼�。
 *
 * ## 实现建议
 *
 * - `supports()` 用于「这个事件与我无关」（如 webhook 不处理审批申请）——**只判事件维度**，
 *   场景级路由（读写/风险过滤）由 {@see ClosureRouter} 统一做，避免两处判定漂移；
 * - 下游包缺失时用 `class_exists` 守卫并 `skipped('下游未安装：wise-xxx')`，**不报 failed**
 *  （没装包不是故障），与 `ApprovalManager::notify()` 的既有守卫思路一致。
 */
interface ClosureConnectorInterface
{
    /**
     * 连接器名（配置里用这个名字路由：`notification` / `webhook` / `audit` / …）
     */
    public function name(): string;

    /**
     * 是否处理该事件（**只判事件维度**，不判读写/风险）
     */
    public function supports(ClosureEvent $event): bool;

    /**
     * 投递（**不得抛异常**；失败请返回 `ConnectorResult::failed()`）
     */
    public function dispatch(ClosureEvent $event): ConnectorResult;
}
