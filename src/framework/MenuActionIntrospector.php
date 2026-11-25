<?php
declare(strict_types=1);

namespace wise\agent\framework;

use wise\agent\tool\ToolRegistry;

/**
 * 后台菜单/动作内省器
 *
 * 将框架已有的后台菜单导航内省为 AI 可调用工具，
 * 让 AI "像管理员一样理解后台"，回答导航类问题。
 *
 * 只暴露菜单元数据、不执行任意控制器动作；
 * 后续版本可在 RBAC 护栏下扩展"执行控制器动作"。
 *
 * 注册的工具：
 *   - wa_menu_tree  后台菜单导航树
 *   - wa_menu_help  按关键词搜索功能菜单位置
 *
 * ## A1 升级（当前进度：A1-2 已落地内省）
 *
 * 「执行控制器动作」的实现落在 `wise\agent\menu\`（一个升级项一个目录，与 A4 的 `console/` 同策略）：
 *   - `MenuActionIntrospector`（本类）升级为**注册入口**，保持 FQCN 与只读导航不变；
 *   - `wise\agent\menu\MenuActionIntrospector` 负责「菜单 → 控制器动作」内省（{@see self::introspectActions()}）；
 *   - A1-4 将补 `registerActionTools()`（生成并注册动作工具），此处暂不提供空实现。
 */
class MenuActionIntrospector
{
    /**
     * 注册框架菜单工具到 ToolRegistry
     *
     * 使用 builtin 命名空间（与内置工具一致），
     * 工具名称即注册键，保证 LLM Function Calling 可正确解析。
     *
     * @param ToolRegistry|null $registry 目标注册表（null 则用单例）
     * @return int 注册的工具数量
     */
    public function registerTools(?ToolRegistry $registry = null): int
    {
        $registry = $registry ?? ToolRegistry::instance();

        $registry->register(new MenuTreeTool());
        $registry->register(new MenuHelpTool());

        return 2;
    }

    /**
     * 内省「菜单可达的 CrudAction 动作」清单（A1-2）
     *
     * 委托 `wise\agent\menu\MenuActionIntrospector`，产出 `{controller}::{action}` 键的
     * {@see \wise\agent\menu\MenuActionMeta} 集合（含权限锚点菜单、权限标识、参数 Schema）。
     *
     * @param bool $force 强制重建内省缓存
     * @return array<string, \wise\agent\menu\MenuActionMeta>
     */
    public function introspectActions(bool $force = false): array
    {
        return (new \wise\agent\menu\MenuActionIntrospector())->resolve($force);
    }
}
