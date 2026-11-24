<?php
declare(strict_types=1);

namespace wise\agent\attribute;

use Attribute;

/**
 * AI 动作声明（wise-agent 私有调用契约）
 *
 * ## 定位
 *
 * 只由 `wise\agent` 读取，用于把**控制器上的非标准动作**（不在
 * {@see \wise\agent\menu\MenuActionMeta::EXPOSED_ACTIONS} 白名单内的 public 方法）
 * 暴露成 AI 工具。菜单 / 权限由框架与其它 wise-* 包各自生成，**不读本注解**。
 *
 * ## 两类动作
 *
 * - **标准动作**（getList / save / update / delete / restore / forceDelete / status / sort）：
 *   无需注解，控制器存在同名 public 方法即被发现；
 * - **非标准动作**（resetPassword / assignRole / dragsort / 自定义业务动作）：
 *   必须显式声明本注解，否则不会被 AI 发现。
 *
 * 标准动作也可以加本注解，但只用于覆盖 LLM 语义（描述 / 参数 / 风险），
 * 不改变「是否存在该动作」这一事实。
 *
 * ## 为什么没有 route / method / ident
 *
 * 这三者属于「人工侧」事实：route 由控制器与动作名推导、method 由权限行给出、
 * ident 由菜单派生。写在注解里会产生第二份事实源并必然漂移。
 *
 * ## 安全默认
 *
 * - `access` 必填（read / write / admin），不提供「静默默认」；
 * - `params` 对非只读动作必填：没有参数 Schema 的写动作不生成工具
 *   （否则 LLM 只能靠猜，调用必失败）；
 * - `confirm` 默认 true，且 `MenuActionMeta::requireConfirmation()` 只会加严；
 * - 数组入参目前只支持 `ids` 与 `字段__in` / `字段__notin`（与执行器白名单一致）。
 *
 * 用法：
 * ```php
 * #[AgentAction(
 *     label: '重置管理员密码',
 *     description: '按管理员 ID 重置登录密码，返回临时密码',
 *     access: 'write',
 *     risk: 'high',
 *     params: [
 *         'id'       => ['type' => 'integer', 'description' => '管理员 ID'],
 *         'password' => ['type' => 'string', 'description' => '新密码'],
 *     ],
 *     required: ['id', 'password'],
 * )]
 * public function resetPassword() { ... }
 * ```
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class AgentAction
{
    /**
     * @param string      $label       工具短名（LLM 选择时展示）
     * @param string      $description 工具描述（说明能力与边界）
     * @param array       $params      参数 JSON Schema 的 properties 部分
     * @param array       $required    必填参数名列表
     * @param string      $access      访问类型：read|write|admin
     * @param string      $risk        风险等级：low|medium|high
     * @param bool        $confirm     是否需人工确认（策略只会加严）
     * @param string|null $permission  可选：覆盖 AI 能力权限标识（默认 menu.action.*）
     */
    public function __construct(
        public string $label,
        public string $description = '',
        public array $params = [],
        public array $required = [],
        public string $access = 'write',
        public string $risk = 'medium',
        public bool $confirm = true,
        public ?string $permission = null
    ) {
    }
}
