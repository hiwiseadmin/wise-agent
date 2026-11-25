<?php
declare(strict_types=1);

namespace wise\agent\menu;

use think\helper\Str;
use wise\agent\security\CapabilityAccess;

/**
 * 单条「控制器动作」元数据 DTO（A1-1）
 *
 * 把「后台控制器的一个 CrudAction 标准动作」归一化为一条 AI 可调用能力：
 * 身份（controller + action）、来源（宿主 / 插件）、权限锚点（菜单）、
 * 治理结论（access / risk / 是否需确认）、参数 Schema。
 *
 * 设计要点：
 *   - **单文件 DTO**：只承载数据 + 纯函数（权限标识、工具名、序列化），
 *     不依赖容器、不碰数据库，便于序列化缓存（A1-2）与单测；
 *   - **动作语义表在此唯一定义**：`ACCESS_BY_ACTION` / `RISK_BY_ACTION` 是
 *     护栏（A1-4 `MenuActionGuard`）与工具（`MenuActionTool`）的共同输入，
 *     避免两处各写一份导致漂移；
 *   - **只暴露返回 JSON 的动作**：`index/add/edit/preview` 返回 `$this->fetch()`
 *     （HTML 模板字符串），对 LLM 无价值且会触发模板渲染，**不生成工具**
 *     ＼�。
 */
final class MenuActionMeta implements \JsonSerializable
{
    /** 来源：宿主应用控制器 */
    public const SOURCE_HOST = 'host';

    /** 插件来源前缀，完整值形如 `plugin:smartstock` */
    public const SOURCE_PLUGIN_PREFIX = 'plugin:';

    /** 生成式 CRUD 控制器的插件标识占位（宿主为 `host`） */
    public const HOST_IDENT = 'host';

    /**
     * 可暴露为工具的动作（`CrudAction` 中返回 JSON 的动作）
     *
     * @var string[]
     */
    public const EXPOSED_ACTIONS = [
        'getList', 'save', 'update', 'delete', 'restore', 'forceDelete', 'status', 'sort',
    ];

    /**
     * 只渲染 HTML 的动作：仅内省、不生成工具
     *
     * @var string[]
     */
    public const HTML_ACTIONS = ['index', 'add', 'edit', 'preview'];

    /**
     * 动作 → 访问类型（`CapabilityAccess`）
     *
     * @var array<string, string>
     */
    public const ACCESS_BY_ACTION = [
        'getList'     => CapabilityAccess::READ,
        'save'        => CapabilityAccess::WRITE,
        'update'      => CapabilityAccess::WRITE,
        'delete'      => CapabilityAccess::WRITE,
        'restore'     => CapabilityAccess::WRITE,
        'forceDelete' => CapabilityAccess::ADMIN,
        'status'      => CapabilityAccess::WRITE,
        'sort'        => CapabilityAccess::WRITE,
    ];

    /**
     * 动作 → 风险等级
     *
     * @var array<string, string>
     */
    public const RISK_BY_ACTION = [
        'getList'     => CapabilityAccess::RISK_LOW,
        'save'        => CapabilityAccess::RISK_MEDIUM,
        'update'      => CapabilityAccess::RISK_MEDIUM,
        'delete'      => CapabilityAccess::RISK_HIGH,
        'restore'     => CapabilityAccess::RISK_MEDIUM,
        'forceDelete' => CapabilityAccess::RISK_HIGH,
        'status'      => CapabilityAccess::RISK_MEDIUM,
        'sort'        => CapabilityAccess::RISK_MEDIUM,
    ];

    /**
     * @param string $controller 控制器 FQCN，如 `plugin\smartstock\controller\Product`
     * @param string $action     动作名，如 `getList`
     * @param string $source     来源：`host` 或 `plugin:{ident}`（构造时可由 FQCN 推导）
     * @param string $menuPath   权限锚点菜单 path（由 A1-2 内省填充），如 `/plugin/smartstock/product`
     * @param string $menuName   权限锚点菜单名称
     * @param string $menuIdent  权限锚点权限标识（`MenuPermissionBridge::generateIdent($path)` 结果）
     * @param string $access     访问类型，默认按 `ACCESS_BY_ACTION` 推导
     * @param string $risk       风险等级，默认按 `RISK_BY_ACTION` 推导
     * @param array  $parameters 动作参数 JSON Schema（由 `MenuActionSchemaResolver` 填充）
     * @param bool   $customized 动作是否由控制器 public 方法承载（由 A1-2 内省判定）
     * @param bool   $enabled    是否通过护栏（由 A1-4 `MenuActionGuard` 回填）
     * @param array  $annotation `#[AgentAction]` 归一化元数据（非标准动作必填）
     * @param bool   $fromAnnotation 动作是否来自注解（决定是否跳过 DB/表单 Schema 推导）
     */
    public function __construct(
        public readonly string $controller,
        public readonly string $action,
        public readonly string $source = self::SOURCE_HOST,
        public readonly string $menuPath = '',
        public readonly string $menuName = '',
        public readonly string $menuIdent = '',
        public string $access = CapabilityAccess::READ,
        public string $risk = CapabilityAccess::RISK_LOW,
        public array $parameters = [],
        public bool $customized = false,
        public bool $enabled = false,
        public array $annotation = [],
        public bool $fromAnnotation = false,
    ) {
    }

    /**
     * 注解展示名（无注解时回落动作名）
     */
    public function getLabel(): string
    {
        $label = trim((string) ($this->annotation['label'] ?? ''));

        return $label !== '' ? $label : $this->action;
    }

    /**
     * 注解描述（无注解时为空串）
     */
    public function getDescription(): string
    {
        return trim((string) ($this->annotation['description'] ?? ''));
    }

    /**
     * 由控制器 FQCN 推导来源标识
     *
     * `plugin\{ident}\controller\X` → `plugin:{ident}`；其余 → `host`。
     */
    public static function detectSource(string $controller): string
    {
        $parts = explode('\\', ltrim($controller, '\\'));

        if (count($parts) >= 3 && $parts[0] === 'plugin' && $parts[1] !== '') {
            return self::SOURCE_PLUGIN_PREFIX . $parts[1];
        }

        return self::SOURCE_HOST;
    }

    /**
     * 构造动作元数据（自动推导 access / risk / source）
     */
    public static function forAction(string $controller, string $action, array $attributes = []): self
    {
        $source = (string) ($attributes['source'] ?? '');
        if ($source === '') {
            $source = self::detectSource($controller);
        }

        $annotation = is_array($attributes['annotation'] ?? null) ? $attributes['annotation'] : [];
        $parameters = is_array($attributes['parameters'] ?? null) ? $attributes['parameters'] : [];

        if ($annotation !== [] && $parameters === []) {
            $parameters = is_array($annotation['schema'] ?? null) ? $annotation['schema'] : [];
        }

        return new self(
            controller: $controller,
            action: $action,
            source: $source,
            menuPath: (string) ($attributes['menuPath'] ?? $attributes['menu_path'] ?? ''),
            menuName: (string) ($attributes['menuName'] ?? $attributes['menu_name'] ?? ''),
            menuIdent: (string) ($attributes['menuIdent'] ?? $attributes['menu_ident'] ?? ''),
            access: (string) ($annotation['access'] ?? $attributes['access'] ?? self::accessForAction($action)),
            risk: (string) ($annotation['risk'] ?? $attributes['risk'] ?? self::riskForAction($action)),
            parameters: $parameters,
            customized: (bool) ($attributes['customized'] ?? false),
            enabled: (bool) ($attributes['enabled'] ?? false),
            annotation: $annotation,
            fromAnnotation: $annotation !== [],
        );
    }

    /**
     * 动作全集（内省用，不做「语言层」限制）
     *
     * @return string[]
     */
    public static function allActions(): array
    {
        return array_merge(self::EXPOSED_ACTIONS, self::HTML_ACTIONS);
    }

    public static function isExposedAction(string $action): bool
    {
        return in_array($action, self::EXPOSED_ACTIONS, true);
    }

    public static function isHtmlAction(string $action): bool
    {
        return in_array($action, self::HTML_ACTIONS, true);
    }

    public static function accessForAction(string $action): string
    {
        return self::ACCESS_BY_ACTION[$action] ?? CapabilityAccess::WRITE;
    }

    public static function riskForAction(string $action): string
    {
        return self::RISK_BY_ACTION[$action] ?? CapabilityAccess::RISK_MEDIUM;
    }

    /**
     * 从序列化数组还原（供 A1-2 的持久化缓存消费）
     */
    public static function fromArray(array $data): self
    {
        $controller = (string) ($data['controller'] ?? '');
        $action     = (string) ($data['action'] ?? '');

        return new self(
            controller: $controller,
            action: $action,
            source: (string) ($data['source'] ?? self::detectSource($controller)),
            menuPath: (string) ($data['menu_path'] ?? ''),
            menuName: (string) ($data['menu_name'] ?? ''),
            menuIdent: (string) ($data['menu_ident'] ?? ''),
            access: (string) ($data['access'] ?? self::accessForAction($action)),
            risk: (string) ($data['risk'] ?? self::riskForAction($action)),
            parameters: is_array($data['parameters'] ?? null) ? $data['parameters'] : [],
            customized: (bool) ($data['customized'] ?? false),
            enabled: (bool) ($data['enabled'] ?? false),
            annotation: is_array($data['annotation'] ?? null) ? $data['annotation'] : [],
            fromAnnotation: (bool) ($data['from_annotation'] ?? ($data['annotation'] ?? []) !== []),
        );
    }


    /** 来源是否为插件控制器 */
    public function isPlugin(): bool
    {
        return str_starts_with($this->source, self::SOURCE_PLUGIN_PREFIX);
    }

    /**
     * 插件标识（宿主为 `host`）
     */
    public function getIdent(): string
    {
        if (!$this->isPlugin()) {
            return self::HOST_IDENT;
        }

        $ident = substr($this->source, strlen(self::SOURCE_PLUGIN_PREFIX));

        return $ident !== '' ? $ident : self::HOST_IDENT;
    }

    /**
     * 「目标名」的统一推导（A1/A2/A3 共用，单一实现）
     *
     * `{ident}.{控制器段蛇形，多段以点连接}`：
     *
     * | 控制器 FQCN | 目标名 |
     * |---|---|
     * | `app\admin\controller\Menu` | `host.menu` |
     * | `app\admin\controller\LoginLog` | `host.login_log` |
     * | `app\admin\controller\Market\Plugin` | `host.market.plugin` |
     * | `plugin\smartstock\controller\Product` | `smartstock.product` |
     * | `plugin\smartstock\controller\admin\Warehouse` | `smartstock.admin.warehouse` |
     *
     * 为什么必须共用：A3 的查询目标（`lq_query` 的 `target`）与 A2 的表单目标（`form_fields` 的 `target`）
     * 是同一批东西，若两处各推导一次，AI 从 A3 学到的目标名在 A2 就会对不上。
     *
     * ⚠️ 与 {@see self::getControllerName()} 的差别：后者只取**最后一段**（用于工具名/权限标识的既有约定），
     * 本方法保留**全部尾段**（`host.market.plugin`），避免分层控制器重名。
     */
    public static function targetNameOf(string $controller): string
    {
        $parts = explode('\\', ltrim($controller, '\\'));
        $index = array_search('controller', $parts, true);

        $tail = $index === false ? [(string) end($parts)] : array_slice($parts, $index + 1);

        $name = implode('.', array_filter(
            array_map(static fn(string $v): string => trim(Str::snake(trim($v))), $tail),
            static fn(string $v): bool => $v !== ''
        ));

        $ident = ($parts[0] ?? '') === 'plugin' ? ($parts[1] ?? '') : self::HOST_IDENT;

        return ($ident !== '' ? $ident : self::HOST_IDENT) . '.' . $name;
    }

    /**
     * 控制器短名（蛇形小写），如 `Product` → `product`、`ProductCategory` → `product_category`
     */
    public function getControllerName(): string
    {
        $short = substr((string) strrchr('\\' . $this->controller, '\\'), 1);

        return $short === '' ? $this->controller : Str::snake($short);
    }

    /**
     * AI 能力权限标识：`menu.action.{ident}.{controller}.{action}`
     *
     * 与自动 CRUD 工具的 `plugin:{ident}.auto.*`、A4 的 `ai.cmd.*` 完全隔离。
     */
    public function getPermission(): string
    {
        $override = trim((string) ($this->annotation['permission'] ?? ''));
        if ($override !== '') {
            return $override;
        }

        return sprintf('menu.action.%s.%s.%s', $this->getIdent(), $this->getControllerName(), $this->action);
    }

    /**
     * LLM Function Calling 名称：`ma_{ident}_{controller}_{action}`
     *
     * 只允许 `[a-zA-Z0-9_]`（与 A4 的 `cmd_` 前缀策略一致，避免 `:` / `\` 非法）。
     */
    public function getToolName(): string
    {
        $raw = sprintf('ma_%s_%s_%s', $this->getIdent(), $this->getControllerName(), $this->action);

        return (string) preg_replace('/[^a-zA-Z0-9_]+/', '_', strtolower($raw));
    }

    /**
     * 菜单入口路径（供工具描述展示，LLM 可据此告诉用户「从哪里点」）
     */
    public function getMenuPath(): string
    {
        return $this->menuPath;
    }


    /**
     * 是否只读（读动作无需确认、可直接在沙箱外执行）
     */
    public function isReadOnly(): bool
    {
        return $this->access === CapabilityAccess::READ;
    }

    /**
     * 是否需要人工确认＼�
     *
     * 只读 → false；admin 或 high 风险 → 恒 true（策略只能加严）；
     * 其余写类动作 → 由 `menu_actions.write_requires_confirmation` 决定。
     */
    public function requireConfirmation(): bool
    {
        if ($this->isReadOnly()) {
            return false;
        }

        if ($this->access === CapabilityAccess::ADMIN || $this->risk === CapabilityAccess::RISK_HIGH) {
            return true;
        }

        if ($this->fromAnnotation && ($this->annotation['confirm'] ?? false) === true) {
            return true;
        }

        return (bool) self::config('menu_actions.write_requires_confirmation', true);
    }

    /**
     * 是否是可执行的 AI 工具
     *
     * 标准动作看白名单；非标准动作必须带 `#[AgentAction]`；
     * HTML 动作（index/add/edit/preview）即使被注解也一律不生成工具。
     */
    public function isToolCandidate(): bool
    {
        if (self::isHtmlAction($this->action)) {
            return false;
        }

        return self::isExposedAction($this->action) || $this->fromAnnotation;
    }


    /**
     * 填充参数 Schema（由 `MenuActionSchemaResolver` 产出）
     */
    public function withParameters(array $parameters): self
    {
        $this->parameters = $parameters;

        return $this;
    }

    /**
     * 填充权限锚点菜单（由 A1-2 内省产出）
     */
    public function withAnchor(string $path, string $name = '', string $ident = ''): self
    {
        return new self(
            controller: $this->controller,
            action: $this->action,
            source: $this->source,
            menuPath: $path,
            menuName: $name,
            menuIdent: $ident,
            access: $this->access,
            risk: $this->risk,
            parameters: $this->parameters,
            customized: $this->customized,
            enabled: $this->enabled,
            annotation: $this->annotation,
            fromAnnotation: $this->fromAnnotation,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'controller'     => $this->controller,
            'action'         => $this->action,
            'source'         => $this->source,
            'ident'          => $this->getIdent(),
            'controller_name' => $this->getControllerName(),
            'permission'     => $this->getPermission(),
            'tool_name'      => $this->getToolName(),
            'access'         => $this->access,
            'risk'           => $this->risk,
            'requires_confirmation' => $this->requireConfirmation(),
            'exposed'        => $this->isToolCandidate(),
            'customized'     => $this->customized,
            'menu_path'      => $this->menuPath,
            'menu_name'      => $this->menuName,
            'menu_ident'     => $this->menuIdent,
            'parameters'     => $this->parameters,
            'annotation'     => $this->annotation,
            'from_annotation' => $this->fromAnnotation,
            'enabled'        => $this->enabled,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * 读取配置，容器不可用时回退默认值（脱离框架的单元测试约定，与 A4 一致）
     */
    private static function config(string $key, mixed $default): mixed
    {
        if (!function_exists('config')) {
            return $default;
        }

        try {
            return config('wise-agent.' . $key, $default) ?? $default;
        } catch (\Throwable) {
            return $default;
        }
    }
}
