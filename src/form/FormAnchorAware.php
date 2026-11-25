<?php
declare(strict_types=1);

namespace wise\agent\form;

/**
 * 「表单目标工具」共用的锚点权限判定（A2-4 / 验收 12）
 *
 * 用法（在工具的 `execute()` 里、解析出控制器之后）：
 *
 * ```php
 * if (($blocked = $this->anchorBlocked($controller, $targetName)) !== null) {
 *     return $this->encode($blocked);
 * }
 * ```
 *
 * 判定实现见 {@see FormAnchorGuard}：**复用 A1 的中间件链**，不新增权限概念。
 * `anchorGuard()` 是 `protected` 接缝，单测可覆写以离线覆盖「拒绝 → 与 A1 相同的 403」分支。
 *
 * ⚠️ 该 trait 假定使用它的工具持有 `private array $options`（trait 会被扁平化进类，
 * 因此可以直接读取该属性）—— 本包四个表单工具都满足这个前提。
 */
trait FormAnchorAware
{
    private ?FormAnchorGuard $formAnchorGuard = null;

    /**
     * 锚点判定器（`protected` 接缝：单测覆写即可离线覆盖拒绝分支）
     */
    protected function anchorGuard(): FormAnchorGuard
    {
        return $this->formAnchorGuard ??= new FormAnchorGuard($this->options);
    }

    /**
     * 该目标是否被锚点权限拦截
     *
     * @return array<string, mixed>|null `null` = 放行；数组 = 与 A1 同核的失败结构
     */
    protected function anchorBlocked(string $controller, string $target): ?array
    {
        try {
            return $this->anchorGuard()->check($controller, $target);
        } catch (\Throwable) {
            return null;
        }
    }
}
