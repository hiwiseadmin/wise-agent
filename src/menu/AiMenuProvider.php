<?php
declare(strict_types=1);

namespace wise\agent\menu;

use wise\support\contracts\MenuItemProvider;
use wise\support\menu\MenuMount;

/**
 * wise-agent 后台菜单声明（ExtPoints::MENU_ITEM）—— 启动台形态
 *
 * 统一承载本包全部后台入口（合并原 AiApprovalMenuProvider / TaskMenuProvider /
 * AiPortalMenuProvider 三个声明）：
 *
 *   AI 助手(入口, 启动台, 可钉选)
 *     ├── 对话与设置(1000) → AI 对话 / AI 设置
 *     ├── 能力审批(1010)   → AI 能力审批
 *     └── 观测治理(1020)   → 用量仪表盘 / AI 任务中心(tasks.enabled 门控)
 *
 * 菜单不硬编码 INSERT，由宿主 app\admin\service\MenuItemSync 扫描扩展点幂等落地
 * （含菜单派生权限联动）。升级时改这里再执行 `php think menu:sync` 即可。
 *
 * 形态约定（与 wise-market 的 MarketMenuProvider 一致）：
 *   - 入口容器 `mount = MenuMount::LAUNCHER`：整包在「应用启动台」只出**一条**，可钉选；
 *   - 业务分组 `anchor = 'launcher'` 挂到入口下，彼此同级（层级 ≤ 3）。
 *
 * 派生的权限标识（path → lower(str_replace('/', '.', snake(path)))）：
 *   admin.ai_chat.index / admin.ai_config.index / admin.ai_approval.* /
 *   admin.ai.billing.index / admin.ai_task.*
 */
class AiMenuProvider implements MenuItemProvider
{
    public function ident(): string
    {
        return 'wise-agent';
    }

    public function containers(): array
    {
        return [
            [
                'key'   => 'entry',
                'name'  => 'AI 助手',
                'icon'  => 'bi bi-robot',
                'sort'  => 1000,
                'mount' => MenuMount::LAUNCHER,
            ],

            [
                'key'    => 'chat',
                'name'   => '对话与设置',
                'icon'   => 'bi bi-chat-dots',
                'anchor' => 'launcher',
                'sort'   => 1000,
            ],
            [
                'key'    => 'approval',
                'name'   => '能力审批',
                'icon'   => 'bi bi-shield-check',
                'anchor' => 'launcher',
                'sort'   => 1010,
            ],
            [
                'key'    => 'insight',
                'name'   => '观测治理',
                'icon'   => 'bi bi-graph-up',
                'anchor' => 'launcher',
                'sort'   => 1020,
            ],
        ];
    }

    public function items(): array
    {
        $items = [
            [
                'container'   => 'chat',
                'name'        => 'AI 对话',
                'icon'        => 'bi bi-chat-dots',
                'path'        => '/admin/ai_chat/index',
                'sort'        => 10,
                'type'        => 'menu',
                'status'      => 1,
                'description' => 'AI 助手三栏聊天界面',
            ],
            [
                'container'   => 'chat',
                'name'        => 'AI 设置',
                'icon'        => 'bi bi-sliders',
                'path'        => '/admin/ai_config/index',
                'sort'        => 20,
                'type'        => 'menu',
                'status'      => 1,
                'description' => 'AI 模型提供商配置',
            ],

            [
                'container'   => 'approval',
                'name'        => 'AI 能力审批',
                'icon'        => 'bi bi-shield-check',
                'path'        => '/admin/ai_approval/index',
                'sort'        => 10,
                'type'        => 'menu',
                'status'      => 1,
                'description' => 'AI 工具 / 技能调用的确认与审批单管理',
                'child'       => [
                    ['name' => '审批列表', 'path' => '/admin/ai_approval/getlist', 'type' => 'button'],
                    ['name' => '审批预览', 'path' => '/admin/ai_approval/preview', 'type' => 'button'],
                    ['name' => '批准申请', 'path' => '/admin/ai_approval/approve', 'type' => 'button'],
                    ['name' => '驳回申请', 'path' => '/admin/ai_approval/reject',  'type' => 'button'],
                    ['name' => '撤回申请', 'path' => '/admin/ai_approval/cancel',  'type' => 'button'],
                    ['name' => '审批统计', 'path' => '/admin/ai_approval/stat',    'type' => 'button'],
                    ['name' => '清理过期', 'path' => '/admin/ai_approval/expire',  'type' => 'button'],
                ],
            ],

            [
                'container'   => 'insight',
                'name'        => '用量仪表盘',
                'icon'        => 'bi bi-graph-up',
                'path'        => '/admin/ai/billing/index',
                'sort'        => 10,
                'type'        => 'menu',
                'status'      => 1,
                'description' => 'AI 用量与成本估算仪表盘',
            ],
            [
                'container'   => 'insight',
                'name'        => 'AI 会话管理',
                'icon'        => 'bi bi-chat-square-text',
                'path'        => '/admin/ai_session/index',
                'sort'        => 30,
                'type'        => 'menu',
                'status'      => 1,
                'description' => '按会话查看与 AI 的完整对话记录',
                'child'       => [
                    ['name' => '会话列表', 'path' => '/admin/ai/session/list', 'type' => 'button'],
                    ['name' => '会话详情', 'path' => '/admin/ai/session/get', 'type' => 'button'],
                    ['name' => '删除会话', 'path' => '/admin/ai/session/delete', 'type' => 'button'],
                ],
            ],
            [
                'container'   => 'insight',
                'name'        => 'AI 执行日志',
                'icon'        => 'bi bi-journal-text',
                'path'        => '/admin/ai_execution_log/index',
                'sort'        => 40,
                'type'        => 'menu',
                'status'      => 1,
                'description' => '每一次工具调用的入口、能力、判决结果与耗时',
                'child'       => [
                    ['name' => '日志列表', 'path' => '/admin/ai/execution-log/list', 'type' => 'button'],
                    ['name' => '日志详情', 'path' => '/admin/ai/execution-log/get', 'type' => 'button'],
                    ['name' => '日志统计', 'path' => '/admin/ai/execution-log/stat', 'type' => 'button'],
                ],
            ],
        ];

        if ((bool) config('wise-agent.tasks.enabled', false)) {
            $items[] = [
                'container'   => 'insight',
                'name'        => 'AI 任务中心',
                'icon'        => 'bi bi-list-task',
                'path'        => '/admin/ai_task/index',
                'sort'        => 20,
                'type'        => 'menu',
                'status'      => 1,
                'description' => 'AI 任务执行轨迹、状态与用量统计',
                'child'       => [
                    ['name' => '任务查看', 'path' => '/admin/ai_task/view', 'type' => 'button'],
                ],
            ];
        }

        return $items;
    }
}
