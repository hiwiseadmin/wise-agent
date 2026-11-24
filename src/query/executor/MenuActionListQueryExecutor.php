<?php
declare(strict_types=1);

namespace wise\agent\query\executor;

use wise\agent\menu\MenuActionInvoker;
use wise\agent\menu\MenuActionMeta;
use wise\agent\query\ListQueryTarget;

/**
 * 默认执行器：委托 A1 的菜单动作执行面（A3-3）
 *
 * 编译产物原样交给 `MenuActionInvoker::invoke($controller, 'getList', $params)`，因此自动获得：
 *
 * - **权限**：与人工访问 `/{app}/{controller}/getList` 完全同一套判定（A1-5 的人工同款中间件链）；
 * - **审计**：`AuditLog` / `OperationLog` 与人工操作同源（含 `WiseAgent/menu-action` 标记）；
 * - **业务语义**：控制器里重写的 `getList`、钩子、软删、字段可读性策略全部生效。
 *
 * ⚠️ **软删口径**：A1 走模型查询（带软删过滤），因此「行数」与 `Db::name()` 直查不同
 * （A3-2 验收实测差 14 行）。A3 一律以 A1 的返回为准。
 */
final class MenuActionListQueryExecutor implements ListQueryExecutorInterface
{
    private MenuActionInvoker $invoker;

    /**
     * @param array<string, mixed> $options `list_query.*` 覆盖项（透传给 A1 的执行器）
     */
    public function __construct(?MenuActionInvoker $invoker = null, private array $options = [])
    {
        $this->invoker = $invoker ?? new MenuActionInvoker();
    }

    /**
     * {@inheritDoc}
     */
    public function execute(ListQueryTarget $target, array $params): array
    {
        $meta = MenuActionMeta::forAction($target->controller, $target->getListAction, [
            'menuPath' => $target->anchorPath,
            'menuName' => $target->title,
        ]);

        return $this->invoker->invoke($meta, $params);
    }

    /**
     * 上一次执行的轨迹（A1 的合成请求/归一化过程，供调试与审计）
     *
     * @return array<int, array{step:string, message:string}>
     */
    public function trace(): array
    {
        return $this->invoker->trace();
    }
}
