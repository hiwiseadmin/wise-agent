<?php
declare(strict_types=1);

namespace wise\agent\security;

use wise\agent\event\ToolExecute;
use wise\agent\menu\MenuActionGuard;
use wise\agent\menu\MenuActionTool;
use wise\agent\tool\ToolRegistry;

/**
 * 跨能力只读沙箱＼�
 *
 * `ToolExecute(BEFORE)` 的**第二个使用者**，排在 {@see \wise\agent\listener\ToolSecurityCheck}
 * 之后（注册顺序即执行顺序）。职责：把「本来会执行的写类能力」降级为**影响面预演**。
 *
 * ## 口径铁律＼�
 * - **只加严，不放松**：沙箱不改变权限/审批判定结果，只是把「执行」变成「预演」；
 * - **双保险**：入口先检 `$event->skip`（已被安全层拦下就不出手），且监听器注册顺序在
 *   `ToolSecurityCheck` 之后；
 * - **沙箱不等于审批**：命中沙箱不产生审批单（因为根本不会执行）；
 * - **无权限者连预演都拿不到**：本层在权限之后 ⇒ 无权限者先被权限拒绝。
 *
 * ## 影响面两级＼�不编造数字）
 * - `menu.action.*`（MenuActionTool）：复用 {@see MenuActionGuard::preview()} 的**真实影响面**；
 * - 其它工具/技能：静态预演（能力名 + 参数摘要 + `surface=unknown`）。
 *
 * @package wise\agent
 */
class SandboxGuard
{
    public function __construct(
        private readonly array $options = [],
        private readonly ?GrayRelease $gray = null,
        /** 可注入的 actor 解析器（单测用）：fn(): array{user_type:string, user_id:int, roles:string[]} */
        private readonly ?\Closure $actorResolver = null,
    ) {
    }

    public function handle(ToolExecute $event): void
    {
        if ($event->phase !== ToolExecute::PHASE_BEFORE) {
            return;
        }

        if ($event->skip) {
            return;
        }

        $tool = $this->resolveTool($event->toolName);
        if ($tool === null) {
            return;
        }

        $access = $tool->getAccess();

        if ($access === CapabilityAccess::READ) {
            return;
        }

        $gray = $this->grayDecide($event->toolName);
        if ($gray !== null) {
            if ($gray['action'] === 'denied') {
                $this->blockDenied($event, $gray);
                return;
            }
            $this->blockSandbox($event, $tool, $access, 'gray_not_released', $gray);
            return;
        }

        if (!(bool) $this->opt('enabled', false)) {
            return;
        }

        if (!$this->shouldSandbox($event->toolName, $access)) {
            return;
        }

        $this->blockSandbox($event, $tool, $access, 'sandbox_mode', null);
    }

    /**
     * 是否命中沙箱（mode=off 不命中；all_writes 命中所有写类；new_only 只命中「不在可信清单」的）
     */
    private function shouldSandbox(string $toolName, string $access): bool
    {
        $mode = (string) $this->opt('mode', 'off');

        if ($mode === 'off') {
            return false;
        }

        if ($mode === 'all_writes') {
            return true;
        }

        $trusted = (array) $this->opt('trusted_capabilities', []);
        foreach ($trusted as $prefix) {
            if (str_starts_with($toolName, (string) $prefix)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 灰度判定（先 gray）；返回 null 表示「未拦截」（gray disabled 或 allowed，继续 sandbox）
     *
     * @return array{action: string, reason: string, rule: string}|null
     */
    private function grayDecide(string $toolName): ?array
    {
        $gray = $this->grayGuard();
        if ($gray === null) {
            return null;
        }

        [$userType, $userId, $roles] = $this->actor();

        $result = $gray->decide($toolName, $userType, $userId, $roles);

        return $result['action'] === 'allowed' ? null : $result;
    }

    private function grayGuard(): ?GrayRelease
    {
        if ($this->gray !== null) {
            return $this->gray;
        }

        $grayConfig = (array) $this->config('gray', []);
        if ($grayConfig === []) {
            return null;
        }

        return new GrayRelease($grayConfig);
    }

    /**
     * 当前 actor（user_type / user_id / roles）；单测用 actorResolver 注入替身
     *
     * @return array{0: string, 1: int, 2: string[]}
     */
    private function actor(): array
    {
        if ($this->actorResolver !== null) {
            $actor = ($this->actorResolver)();
            return [
                (string) ($actor['user_type'] ?? ''),
                (int) ($actor['user_id'] ?? 0),
                (array) ($actor['roles'] ?? []),
            ];
        }

        return [$this->currentUserType(), $this->currentUserId(), $this->currentRoles()];
    }

    private function currentUserType(): string
    {
        try {
            return (string) (new CapabilityAuthorizer())->currentUserType();
        } catch (\Throwable) {
            return 'admin';
        }
    }

    private function currentUserId(): int
    {
        try {
            return (new CapabilityAuthorizer())->currentUserId();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * 从当前登录用户对象提取角色 slug（拿不到则空，exempt_roles/roles 判定自然不命中）
     *
     * @return string[]
     */
    private function currentRoles(): array
    {
        try {
            $user = function_exists('auth_user') ? auth_user() : null;
            if (!is_object($user) || !method_exists($user, 'getRoles')) {
                return [];
            }

            $roles = $user->getRoles();
            $slugs = [];
            foreach ($roles as $role) {
                $slug = is_object($role) ? (string) ($role->slug ?? $role->ident ?? '') : '';
                if ($slug !== '') {
                    $slugs[] = $slug;
                }
            }
            return $slugs;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * 影响面两级：menu.action.* 真实影响面；其它静态预演（surface=unknown）
     *
     * @return array<string, mixed>
     */
    private function buildPreview(\wise\agent\contract\ToolInterface $tool, ToolExecute $event): array
    {
        if ($tool instanceof MenuActionTool) {
            $result = (new MenuActionGuard($this->previewOptions()))->preview(
                $tool->getMeta(),
                $event->arguments
            );

            return $result['data'] ?? $result;
        }

        return [
            'surface'    => 'unknown',
            'capability' => $event->toolName,
            'arguments'  => $this->summarizeArguments($event->arguments),
            'access'     => $tool->getAccess(),
            'risk'       => $tool->getRiskLevel(),
            'note'       => '该能力未提供影响面探测',
        ];
    }

    /**
     * 参数摘要：JSON 化并截断，避免超长入参撑爆 mockResult
     *
     * @return array<string, mixed>
     */
    private function summarizeArguments(array $arguments): array
    {
        $json = json_encode($arguments, JSON_UNESCAPED_UNICODE);

        if ($json === false || strlen($json) <= 2000) {
            return $arguments;
        }

        return ['_truncated' => true, '_summary' => substr($json, 0, 2000) . '…'];
    }

    /**
     * 复用 ToolSecurityCheck 的解析口径：全局注册表优先，回落 config 注册
     */
    private function resolveTool(string $name): ?\wise\agent\contract\ToolInterface
    {
        $tool = ToolRegistry::instance()->normalize($name);
        if ($tool !== null) {
            return $tool;
        }

        $configured = (array) config('wise-agent.tools', []);
        $class      = $configured[$name]['class'] ?? null;

        if (is_string($class) && class_exists($class)) {
            try {
                $instance = app($class);
            } catch (\Throwable) {
                try {
                    $instance = new $class();
                } catch (\Throwable) {
                    return null;
                }
            }

            if ($instance instanceof \wise\agent\contract\ToolInterface) {
                return $instance;
            }
        }

        return null;
    }

    private function previewOptions(): array
    {
        $preview = (array) ($this->options['preview'] ?? $this->config('sandbox.preview', []));
        return is_array($preview) ? $preview : [];
    }

    private function opt(string $key, mixed $default): mixed
    {
        if (array_key_exists($key, $this->options)) {
            return $this->options[$key];
        }

        return $this->config('sandbox.' . $key, $default);
    }

    /**
     * 灰度显式拒绝
     *
     * @param array{action: string, reason: string, rule: string} $gray
     */
    private function blockDenied(ToolExecute $event, array $gray): void
    {
        $event->skip       = true;
        $event->mockResult = json_encode([
            'code'            => 1,
            'error'           => "能力 '{$event->toolName}' 未对你放量（灰度），已拒绝",
            'required_action' => 'denied',
            'gray'            => true,
            'gray_reason'     => $gray['reason'],
            'gray_rule'       => $gray['rule'],
            'tool_name'       => $event->toolName,
        ], JSON_UNESCAPED_UNICODE) ?: '{"code":1,"error":"gray json encode failed"}';
    }

    /**
     * 沙箱预演（source：gray_not_released=灰度未放量 / sandbox_mode=沙箱命中）
     *
     * @param array{action: string, reason: string, rule: string}|null $gray
     */
    private function blockSandbox(
        ToolExecute $event,
        \wise\agent\contract\ToolInterface $tool,
        string $access,
        string $source,
        ?array $gray
    ): void {
        $preview = $this->buildPreview($tool, $event);

        $payload = [
            'code'            => 1,
            'error'           => $source === 'gray_not_released'
                ? "能力 '{$event->toolName}' 未对你放量（灰度），已降级为影响面预演，未做任何修改"
                : "能力 '{$event->toolName}' 处于只读沙箱：本次为影响面预演，未做任何修改",
            'required_action' => 'sandbox',
            'sandbox'         => true,
            'preview'         => $preview,
            'tool_name'       => $event->toolName,
            'access'          => $access,
            'sandbox_source'  => $source,
        ];

        if ($gray !== null) {
            $payload['gray']        = true;
            $payload['gray_reason'] = $gray['reason'];
            $payload['gray_rule']   = $gray['rule'];
        }

        $event->skip       = true;
        $event->mockResult = json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{"code":1,"error":"sandbox json encode failed"}';
    }

    private function config(string $key, mixed $default): mixed
    {
        try {
            return config('wise-agent.' . $key, $default) ?? $default;
        } catch (\Throwable) {
            return $default;
        }
    }
}
