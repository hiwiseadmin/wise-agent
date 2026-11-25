<?php
declare(strict_types=1);

namespace wise\agent\query\executor;

use wise\agent\menu\MenuActionIntrospector;
use wise\agent\menu\MenuActionMeta;
use wise\agent\query\ListQueryTarget;

/**
 * **默认走 A1**（`getList` 走控制器 → 与页面同一条逻辑），仅在下面两种情况下退到独立执行：
 *
 * 1. `mode=model_search`——目标显式声明了手写列表页（插件 `search($params)`）；
 * 2. A1 不可用——`menu_actions.enabled=false`，或该动作不在 A1 的内省结果里。
 *
 * 两条路径的**权限判定完全一致**：A1 路径经 `MenuActionInvoker` 走人工同款中间件链；
 * 独立路径经 `StandaloneListQueryExecutor` 把取数放进**同一条链**内执行。
 *
 * 选择结果会在 `lq_fields` / `discover` 里展示（{@see self::pathOf()}），
 * 让模型与运维都看得见「这次查询走的是哪条路」——避免「同一个查询两条路径结果不同」的隐性漂移。
 */
class ListQueryExecutorResolver implements ListQueryExecutorInterface
{
    /** 走 A1 的 `MenuActionInvoker` */
    public const PATH_MENU_ACTION = 'menu_action';

    /** 走 A3 自己的独立执行 */
    public const PATH_STANDALONE = 'standalone';

    private ListQueryExecutorInterface $menuActionExecutor;

    private ListQueryExecutorInterface $standaloneExecutor;

    private MenuActionIntrospector $introspector;

    /** @var array<string, bool>|null 动作是否被 A1 内省到 */
    private ?array $a1Actions = null;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        private array $options = [],
        ?ListQueryExecutorInterface $menuActionExecutor = null,
        ?ListQueryExecutorInterface $standaloneExecutor = null,
        ?MenuActionIntrospector $introspector = null
    ) {
        $this->menuActionExecutor = $menuActionExecutor ?? new MenuActionListQueryExecutor(null, $options);
        $this->standaloneExecutor = $standaloneExecutor ?? new StandaloneListQueryExecutor($options);
        $this->introspector       = $introspector ?? new MenuActionIntrospector();
    }

    /**
     * {@inheritDoc}
     */
    public function execute(ListQueryTarget $target, array $params): array
    {
        return $this->resolveFor($target)->execute($target, $params);
    }

    /**
     * 该目标本次会用哪条路径执行
     */
    public function resolveFor(ListQueryTarget $target): ListQueryExecutorInterface
    {
        return $this->pathOf($target) === self::PATH_STANDALONE
            ? $this->standaloneExecutor
            : $this->menuActionExecutor;
    }

    /**
     * `menu_action` | `standalone`
     */
    public function pathOf(ListQueryTarget $target): string
    {
        if ($target->mode === ListQueryTarget::MODE_MODEL_SEARCH) {
            return self::PATH_STANDALONE;
        }

        if (!$this->a1Available($target)) {
            return self::PATH_STANDALONE;
        }

        return self::PATH_MENU_ACTION;
    }

    /**
     * A1 是否接管该目标（开关打开 + 动作在内省结果里）
     */
    protected function a1Available(ListQueryTarget $target): bool
    {
        if (!$this->menuActionsEnabled()) {
            return false;
        }

        if (!(bool) $this->opt('standalone_fallback', true) && !$this->a1Knows($target)) {
            return true;
        }

        return $this->a1Knows($target);
    }

    /**
     * A1 是否启用（`wise-agent.menu_actions.enabled`）
     */
    protected function menuActionsEnabled(): bool
    {
        if (array_key_exists('menu_actions_enabled', $this->options)) {
            return (bool) $this->options['menu_actions_enabled'];
        }

        if (!function_exists('config')) {
            return false;
        }

        try {
            return (bool) (config('wise-agent.menu_actions.enabled', false) ?? false);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 动作是否出现在 A1 的内省结果中
     */
    protected function a1Knows(ListQueryTarget $target): bool
    {
        if ($this->a1Actions === null) {
            $this->a1Actions = [];

            try {
                foreach ($this->introspector->resolve(false) as $meta) {
                    if ($meta instanceof MenuActionMeta) {
                        $this->a1Actions[$meta->controller . '::' . $meta->action] = true;
                    }
                }
            } catch (\Throwable) {
                $this->a1Actions = [];
            }
        }

        return isset($this->a1Actions[$target->controller . '::' . $target->getListAction]);
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
            return config('wise-agent.list_query.' . $key, $default) ?? $default;
        } catch (\Throwable) {
            return $default;
        }
    }
}
