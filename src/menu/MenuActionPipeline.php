<?php
declare(strict_types=1);

namespace wise\agent\menu;

use think\Request;
use think\Response;

/**
 * 人工同款中间件链执行器（A1-5）
 *
 * ## 设计原则：AI 走人工的那条链，不另造判定
 *
 * 「AI 代替人工」要求**判定口径同一**。后台人工请求经过
 * `app/admin/middleware.php` 的中间件链（`IpGuard → Rbac → TwoFactor → XssFilter → AuditLog → OperationLog`），
 * 其中 `RbacMiddleware` 会按 `/{app}/{controller}/{action}` + HTTP 方法匹配
 * `auth_permissions` 的行动作级权限点（`type='route'`）。若 AI 自己重写一套权限判定，
 * 两套实现必然漂移（实测已出现：忽略 method 导致 `delete` 恒匹配失败、无权限点时默认放行）。
 *
 * 因此本类**把人工的中间件链原样套在 AI 的动作调用外面**：
 *
 * ```
 * 合成 Request（复制真实请求的 server/header/cookie/session；仅替换 method/params/controller/action/pathinfo）
 *   │
 *   ├─ IpGuardMiddleware      会话 IP 黑白名单 —— 同一会话同一 IP
 *   ├─ RbacMiddleware         登录检查 + 权限版本检查 + 超管 + 路由→权限点匹配 + checkAny/checkAll
 *   ├─ TwoFactorMiddleware    2FA 是否已完成
 *   ├─ XssFilterMiddleware    入参清洗（它操作的是 Request 对象，对合成请求同样生效）
 *   ├─ AuditLog               审计留痕（POST/PUT/DELETE/PATCH 才记，与人工一致）
 *   └─ OperationLog           业务操作日志（按 #[OperationLog] 属性，与人工一致）
 *         │
 *         └─ 最内层：控制器动作调用（或只读沙箱预演）← 真正业务
 * ```
 *
 * 于是决策清单里的 A3（未匹配权限点→按 `default_mode` 拒绝）、A4（白/黑名单旁路）、
 * A6（超管/通配唯一判定）、A7（权限版本检查）**全部由构造满足**，无需逐条实现。
 *
 * ## 拦截识别
 *
 * 每个中间件被包一层，若它**没有调用 `$next`** 即视为拦截（并记录拦截者类名）。
 * 最内层handler 用「捕获变量 + 返回占位 Response」的方式把控制器返回值带出来：
 * 这样 `AuditLog` / `OperationLog` 这类 **`$next` 之后才落盘**的中间件仍能看到一个
 * 合理的 200 响应，从而产出与人工一致的日志。
 *
 * ## 明确排除（不是遗漏，是登记过的豁免）
 *
 * | 中间件 | 排除理由 |
 * |---|---|
 * | `think\middleware\LoadLangPack` | 外层请求已加载语言包，且与授权无关 |
 * | `app\common\middleware\VerifyCsrfToken` | AI 调用是服务端内部调用，没有表单令牌；**不改变授权判定**（CSRF 防的是跨站伪造来源，不是权限） |
 * | `app\admin\middleware\MenuStaleSync` | 菜单懒同步，与授权无关（避免每次动作多一次写检测） |
 *
 * ## 失败取向：fail-closed
 *
 * 链上中间件**类存在但无法实例化**（依赖缺失/配置错误）→ **拒绝执行**（`pipeline_fail_closed=true`）。
 * 反之若该类**根本不存在**（对应包未安装），则从链中剔除 —— 该能力及其安全语义自然不存在，
 * 不属于「本可拦截却被绕过」。
 */
class MenuActionPipeline
{
    /**
     * 默认中间件链（与 `app/admin/middleware.php` 的相对顺序严格一致）
     *
     * @var string[]
     */
    public const DEFAULT_CHAIN = [
        'wise\\login\\middleware\\IpGuardMiddleware',
        'wise\\auth\\middleware\\RbacMiddleware',
        'wise\\login\\middleware\\TwoFactorMiddleware',
        'app\\common\\middleware\\XssFilterMiddleware',
        'wise\\operationlog\\middleware\\AuditLog',
        'wise\\operationlog\\middleware\\OperationLog',
    ];

    /**
     * 登记过的豁免（仅用于文档与 trace，不参与执行）
     *
     * @var array<string, string>
     */
    public const EXCLUDED_MIDDLEWARE = [
        'think\\middleware\\LoadLangPack'              => '外层请求已加载语言包，且与授权无关',
        'app\\common\\middleware\\VerifyCsrfToken'     => '服务端内部调用无表单令牌；不改变授权判定',
        'app\\admin\\middleware\\MenuStaleSync'        => '菜单懒同步，与授权无关',
    ];

    /** 占位响应的状态码（2xx，使审计记录 result=success） */
    private const PLACEHOLDER_STATUS = 200;

    /** @var array<string, mixed> */
    private array $options;

    /** @var array<int, array{step:string, message:string}> */
    private array $trace = [];

    /** 拦截者（最外层先命中者） */
    private ?string $blockedBy = null;

    public function __construct(array $options = [])
    {
        $this->options = $options;
    }

    /**
     * 在人工同款中间件链内执行 `$handler`
     *
     * @param Request  $request 合成请求（method/params/controller/action/pathinfo 已按目标动作设置）
     * @param callable $handler 最内层执行体：`fn(Request $request): mixed`
     * @return array{passed:bool, result:mixed, blocked_by:?string, response:?Response, error:?\Throwable}
     */
    public function run(Request $request, callable $handler): array
    {
        $this->trace     = [];
        $this->blockedBy = null;

        $captured = null;
        $error    = null;

        $innermost = function (Request $req) use ($handler, &$captured): Response {
            $captured = $handler($req);

            return $captured instanceof Response
                ? $captured
                : new MenuActionPipelineResponse('', self::PLACEHOLDER_STATUS);
        };

        $next = $innermost;
        foreach (array_reverse($this->chain()) as $class) {
            $next = $this->wrap($class, $next);
        }

        $response = null;

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            $error = $e;
            $this->addTrace('pipeline.exception', get_class($e) . ': ' . $e->getMessage());
        }

        if ($error !== null) {
            return [
                'passed'     => false,
                'result'     => null,
                'blocked_by' => null,
                'response'   => null,
                'error'      => $error,
            ];
        }

        if ($captured === null) {
            $this->addTrace('pipeline.blocked', (string) $this->blockedBy);

            return [
                'passed'     => false,
                'result'     => null,
                'blocked_by' => $this->blockedBy,
                'response'   => $response instanceof Response ? $response : null,
                'error'      => null,
            ];
        }

        $this->addTrace('pipeline.passed', sprintf('链长 %d', count($this->chain())));

        return [
            'passed'     => true,
            'result'     => $captured,
            'blocked_by' => null,
            'response'   => $response instanceof Response ? $response : null,
            'error'      => null,
        ];
    }

    /**
     * 生效的中间件链（配置覆盖 + 剔除未安装的类）
     *
     * @return string[]
     */
    public function chain(): array
    {
        $configured = $this->opt('pipeline', null);
        $chain      = is_array($configured) && $configured !== [] ? $configured : self::DEFAULT_CHAIN;

        $resolved = [];
        foreach ($chain as $class) {
            if (!is_string($class) || trim($class) === '') {
                continue;
            }

            if (!class_exists($class)) {
                $this->addTrace('pipeline.skipped', $class . '（类不存在，视为未安装）');
                continue;
            }

            $resolved[] = $class;
        }

        return $resolved;
    }

    /**
     * 拦截者类名（`run()` 后有效）
     */
    public function blockedBy(): ?string
    {
        return $this->blockedBy;
    }

    /**
     * @return array<int, array{step:string, message:string}>
     */
    public function trace(): array
    {
        return $this->trace;
    }


    /**
     * 包装单个中间件：未调用 $next 即判定为拦截
     */
    private function wrap(string $class, callable $inner): \Closure
    {
        return function (Request $request) use ($class, $inner): Response {
            $middleware = $this->instantiate($class);

            if ($middleware === null) {
                if ((bool) $this->opt('pipeline_fail_closed', true)) {
                    $this->blockedBy = $class;
                    $this->addTrace('pipeline.failClosed', $class . ' 无法实例化');

                    return new MenuActionPipelineResponse('', 500);
                }

                return $inner($request);
            }

            $calledNext = false;
            $response   = $middleware->handle($request, function (Request $req) use ($inner, &$calledNext) {
                $calledNext = true;

                return $inner($req);
            });

            if (!$calledNext && $this->blockedBy === null) {
                $this->blockedBy = $class;
            }

            return $response instanceof Response ? $response : new MenuActionPipelineResponse('', self::PLACEHOLDER_STATUS);
        };
    }

    /**
     * 从容器解析中间件（失败返回 null）
     */
    protected function instantiate(string $class): ?object
    {
        if (!class_exists($class)) {
            return null;
        }

        try {
            $instance = app()->make($class, [], true);

            return is_object($instance) && method_exists($instance, 'handle') ? $instance : null;
        } catch (\Throwable $e) {
            $this->addTrace('pipeline.instantiateFailed', $class . ': ' . $e->getMessage());

            return null;
        }
    }

    private function opt(string $key, mixed $default): mixed
    {
        if (array_key_exists($key, $this->options)) {
            return $this->options[$key];
        }

        if (!function_exists('config')) {
            return $default;
        }

        try {
            return config('wise-agent.menu_actions.' . $key, $default) ?? $default;
        } catch (\Throwable) {
            return $default;
        }
    }

    private function addTrace(string $step, string $message): void
    {
        $this->trace[] = ['step' => $step, 'message' => $message];
    }
}
