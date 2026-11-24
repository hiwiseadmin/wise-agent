<?php
declare(strict_types=1);

namespace wise\agent\query\executor;

use wise\agent\query\ListQueryTarget;

/**
 * 查询执行器（A3-3 接缝）
 *
 * A3 只负责「自然语言 → 受约束的查询参数」，**执行**由执行器承担，且**默认委托 A1**
 * 审计全部复用 A1 的既有链路，A3 不引入第二个权限概念。
 *
 * 独立执行路径（手写列表页 / A1 未落地）在 A3-4 以另一个实现补上，二者可互换。
 */
interface ListQueryExecutorInterface
{
    /**
     * 执行一次列表查询
     *
     * @param ListQueryTarget      $target 目标
     * @param array<string, mixed> $params 编译产物（与 `RequestQueryBuilder` 同构）
     * @return array<string, mixed> 统一「观察」契约（`code`/`msg`/`data`/`structured`/`text`）
     */
    public function execute(ListQueryTarget $target, array $params): array;
}
