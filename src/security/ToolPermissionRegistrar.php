<?php
declare(strict_types=1);

namespace wise\agent\security;

use wise\agent\contract\ToolInterface;
use wise\agent\skill\SkillRegistry;
use wise\agent\tool\ToolRegistry;

/**
 * AI 能力权限注册器（Tool + Skill）
 *
 * 把 AI 能力的权限标识注册进 wise-auth 的 `auth_permissions` 表，
 * 使其出现在后台「权限管理 → 权限配置」页面，从而可以按角色可视化授权。
 *
 * ## 权限树结构（分类骨架 + 细粒度下钻）
 *
 *   AI 能力                         ai.capability            [分组]
 *   ├─ 全部 AI 能力                 ai.capability.*          [通配，一键全开]
 *   ├─ 只读能力                     ai.access.read           [分组]
 *   │   ├─ 全部只读能力             ai.access.read.*         [通配] ← 日常勾选单元
 *   │   ├─ AI 工具：db_query        ai.tool.db_query         [细粒度下钻]
 *   │   ├─ 插件 smartstock · 查询    plugin:smartstock.auto.list.*
 *   │   └─ 技能：content.translate   ai.skill.content.translate
 *   ├─ 写入能力                     ai.access.write
 *   ├─ 编排执行                     ai.access.execute
 *   ├─ 管理能力                     ai.access.admin
 *   ├─ 全部 AI 工具                 ai.tool.*                [汇总]
 *   ├─ 全部技能                     ai.skill.*               [汇总]
 *   └─ 插件 xxx · 全部自动工具      plugin:xxx.auto.*        [汇总]
 *
 * 授权建议：日常只勾「全部只读能力 / 全部写入能力」等类别通配（新增能力自动继承），
 * 需要例外时再勾细粒度下钻项。类别通配的判定**不是**字符串前缀匹配，
 * 由 `CapabilityAuthorizer` 显式比对（详见 {@see CapabilityAccess} 类注释）。
 *
 * ## 权限来源（ident 去重，按优先级合并）
 *   1. 配置声明      security.tool_permissions.items
 *   2. 扩展点        wise\support\ExtPoint 'ai.tool.permission_provider'
 *   3. 工具注册表    ToolRegistry 中所有声明了 getPermission() 的工具
 *   4. 技能注册表    SkillRegistry 中的 Skill（ai.skill.{name}）
 *   5. 类别 / 兼容通配（可配置开关）
 *
 * ## 触发入口
 *   - 命令行：php think wise:agent:permissions
 *   - 后台：权限配置页「更新权限列表」（POST /admin/permission/updateFromMenuActionApi）
 *   - 安装：app\install\service\PermissionInitService::build()
 */
class ToolPermissionRegistrar
{
    /** 权限类型（wise-auth：route/api/custom/menu） */
    public const TYPE = 'custom';

    /** 权限归属插件标识 */
    public const PLUGIN = 'wise-agent';

    /**
     * 权限提供者扩展点（POLICY_MULTI）
     *
     * 其它包 / 插件可注册 provider（类名或闭包），返回权限定义数组：
     * [
     *     'plugin:demo.auto.*' => ['name' => '演示插件 AI 工具', 'description' => '...'],
     * ]
     *
     * 该扩展点同时登记在「能力市场」中，停用某个 provider 即等价于
     * 关闭对应插件声明的 AI 能力权限（= AI 能力开关）。
     *
     * 与 {@see \wise\support\ExtPoints::AI_TOOL_PERMISSION_PROVIDER} 同源，避免字符串漂移。
     */
    public const EXT_POINT = \wise\support\ExtPoints::AI_TOOL_PERMISSION_PROVIDER;

    /** 工具汇总标识：一键授予全部 ai.tool.* 命名空间下的工具 */
    public const TOOL_ALL_IDENT = 'ai.tool.*';

    /** 技能汇总标识：一键授予全部技能 */
    public const SKILL_ALL_IDENT = CapabilityAccess::SKILL_PREFIX . '.*';

    /** 权限列表排序基准值 */
    private const SORT_BASE = 900;

    /** 是否已尝试从 ProviderCache 恢复插件工具 */
    private bool $hydrated = false;

    /** 自增排序号（保证父节点先于子节点） */
    private int $sort = self::SORT_BASE;

    /**
     * 收集所有需要注册的 AI 能力权限定义
     *
     * @return array<string, array> ident => 权限定义
     */
    public function collect(): array
    {
        if (!$this->hydrated) {
            $this->hydrated = true;
            $this->hydrateFromCache();
        }

        $this->sort = self::SORT_BASE;

        $items  = [];
        $groups = [];

        $items[CapabilityAccess::ROOT_IDENT] = $this->def(
            CapabilityAccess::ROOT_IDENT,
            (string) config('wise-agent.security.tool_permissions.group_name', 'AI 能力'),
            'AI 助手可调用的工具与技能权限。日常建议直接勾选「全部只读能力 / 全部写入能力」等类别通配，'
                . '新增能力会自动继承；需要例外时再勾选下方细粒度条目。',
            0
        );

        if ((bool) config('wise-agent.security.tool_permissions.include_all_wildcard', true)) {
            $items[CapabilityAccess::ALL_IDENT] = $this->def(
                CapabilityAccess::ALL_IDENT,
                '全部 AI 能力',
                '一次性授予全部 AI 能力（工具 + 技能，含后续新增项）。',
                CapabilityAccess::ROOT_IDENT
            );
        }

        $includeAccessWildcard = (bool) config('wise-agent.security.tool_permissions.include_access_wildcard', true);

        foreach (CapabilityAccess::ALL as $access) {
            $groupIdent = CapabilityAccess::groupIdent($access);

            $items[$groupIdent] = $this->def(
                $groupIdent,
                CapabilityAccess::label($access),
                '按访问类型分类的能力分组：' . CapabilityAccess::label($access) . '。',
                CapabilityAccess::ROOT_IDENT
            );

            if ($includeAccessWildcard) {
                $items[CapabilityAccess::wildcardIdent($access)] = $this->def(
                    CapabilityAccess::wildcardIdent($access),
                    '全部' . CapabilityAccess::label($access),
                    '授予该类别下全部能力（含后续新增项）。',
                    $groupIdent
                );
            }
        }

        $details = $this->collectCapabilities();

        foreach ($details as $ident => $detail) {
            if (isset($items[$ident])) {
                continue;
            }

            $items[$ident] = $this->def(
                $ident,
                (string) ($detail['name'] ?? $ident),
                (string) ($detail['description'] ?? ''),
                CapabilityAccess::groupIdent($detail['access']),
                (string) ($detail['plugin'] ?? self::PLUGIN)
            );
        }

        if ((bool) config('wise-agent.security.tool_permissions.include_aggregate_wildcard', true)) {
            $aggregates = [
                self::TOOL_ALL_IDENT => [
                    'name'        => '全部 AI 工具',
                    'description' => '一次性授予全部 AI 工具（ai.tool.* 命名空间，含后续新增工具）。',
                ],
                self::SKILL_ALL_IDENT => [
                    'name'        => '全部技能',
                    'description' => '一次性授予全部技能（ai.skill.* 命名空间，含后续新增技能）。',
                ],
            ];

            foreach ($aggregates as $ident => $detail) {
                if (isset($items[$ident])) {
                    continue;
                }

                $items[$ident] = $this->def(
                    $ident,
                    $detail['name'],
                    $detail['description'],
                    CapabilityAccess::ROOT_IDENT
                );
            }

            foreach ($this->collectPluginAggregates($details) as $ident => $detail) {
                if (isset($items[$ident])) {
                    continue;
                }

                $items[$ident] = $this->def(
                    $ident,
                    (string) $detail['name'],
                    (string) ($detail['description'] ?? ''),
                    CapabilityAccess::ROOT_IDENT,
                    (string) ($detail['plugin'] ?? self::PLUGIN)
                );
            }
        }

        return $items;
    }

    /**
     * 同步权限到 wise-auth 权限表（幂等）
     *
     * @param bool $dryRun 仅预览，不写库
     * @param bool $prune  清理本包托管但已不再收集的 ai.* 历史权限（软删除）
     * @return array{
     *     available:bool, dry_run:bool, prune:bool, total:int, created:int, updated:int,
     *     deleted:int, items:string[], errors:string[]
     * }
     */
    public function sync(bool $dryRun = false, bool $prune = false): array
    {
        $items = $this->collect();

        $result = [
            'available' => $this->isAvailable(),
            'dry_run'   => $dryRun,
            'prune'     => $prune,
            'total'     => count($items),
            'created'   => 0,
            'updated'   => 0,
            'deleted'   => 0,
            'items'     => [],
            'errors'    => [],
        ];

        if (!$result['available']) {
            $result['errors'][] = 'wise-auth 未安装或未启用（缺少 wise\auth\facade\Perm），已跳过 AI 能力权限注册';

            return $result;
        }

        $parentIds = [];

        foreach ($items as $ident => $def) {
            $parentIdent = (string) ($def['parent'] ?? '');
            unset($def['parent']);

            if ($parentIdent !== '' && isset($parentIds[$parentIdent])) {
                $def['parent_id'] = $parentIds[$parentIdent];
            } else {
                $def['parent_id'] = (int) ($def['parent_id'] ?? 0);
            }

            $result['items'][] = $ident;

            if ($dryRun) {
                continue;
            }

            try {
                $existing = \wise\auth\facade\Perm::findByIdent($ident);

                if ($existing) {
                    $def['type'] = (string) ($existing->getData('type') ?: $def['type']);
                    $result['updated']++;
                } else {
                    $result['created']++;
                }

                $parentIds[$ident] = \wise\auth\facade\Perm::registerPermission($def);
            } catch (\Throwable $e) {
                $result['errors'][] = "{$ident}: {$e->getMessage()}";
            }
        }

        if ($prune && !$dryRun) {
            $result['deleted'] = $this->pruneStale(array_keys($items), $result);
        }

        if (!$dryRun && class_exists(\wise\auth\facade\Rbac::class)) {
            try {
                \wise\auth\facade\Rbac::clearAllCache();
            } catch (\Throwable) {
            }
        }

        return $result;
    }

    /**
     * wise-auth 是否可用
     */
    public function isAvailable(): bool
    {
        return class_exists(\wise\auth\facade\Perm::class);
    }


    /**
     * 收集全部能力明细
     *
     * @return array<string, array{name:string,description:string,access:string,plugin:string}>
     */
    protected function collectCapabilities(): array
    {
        $descriptors = [];
        $resolver    = new AccessResolver();
        $fullDetail  = (string) config('wise-agent.security.tool_permissions.detail_level', 'aggregate') === 'full';

        foreach (ToolRegistry::instance()->all() as $name => $tool) {
            if (!$tool instanceof ToolInterface) {
                continue;
            }

            $descriptor = $resolver->resolveTool($tool, is_string($name) ? $name : null);

            if (!$descriptor->requiresPermission()) {
                continue;
            }

            $ident = $descriptor->permission;

            if (!$fullDetail && method_exists($tool, 'getAggregatePermission')) {
                $ident = (string) $tool->getAggregatePermission();
                if ($ident === '') {
                    continue;
                }

                $toolType = method_exists($tool, 'getToolType') ? (string) $tool->getToolType() : '';
                if ($toolType === '' && preg_match('#^plugin:[^.]+\.auto\.([^.]+)\.\*$#', $ident, $m)) {
                    $toolType = $m[1];
                }

                $descriptors[$ident] = [
                    'name'        => '插件 ' . $descriptor->plugin . ' · '
                        . CapabilityAccess::labelOfToolType($toolType),
                    'description' => '插件 ' . $descriptor->plugin . ' 的自动生成工具（操作类型：'
                        . $toolType . '），覆盖该类型下的全部数据表。',
                    'access'      => $descriptor->access,
                    'plugin'      => $descriptor->plugin,
                ];

                continue;
            }

            $descriptors[$ident] = [
                'name'        => 'AI 工具：' . $descriptor->name,
                'description' => $this->summarize($descriptor->description),
                'access'      => $descriptor->access,
                'plugin'      => $descriptor->plugin,
            ];
        }

        if ((bool) config('wise-agent.security.tool_permissions.include_skills', true)) {
            foreach ($this->collectSkills($resolver) as $ident => $detail) {
                $descriptors[$ident] = $detail;
            }
        }

        foreach ((array) config('wise-agent.security.tool_permissions.items', []) as $ident => $def) {
            if (!is_string($ident) || $ident === '') {
                continue;
            }
            if (!is_array($def)) {
                $def = ['name' => (string) $def];
            }

            $descriptors[$ident] = [
                'name'        => (string) ($def['name'] ?? $ident),
                'description' => (string) ($def['description'] ?? ''),
                'access'      => CapabilityAccess::normalize((string) ($def['access'] ?? '')),
                'plugin'      => (string) ($def['plugin'] ?? $this->inferPlugin($ident)),
            ];
        }

        foreach ($this->collectFromExtPoint() as $ident => $def) {
            if (isset($descriptors[$ident])) {
                continue;
            }

            $descriptors[$ident] = [
                'name'        => (string) ($def['name'] ?? $ident),
                'description' => (string) ($def['description'] ?? ''),
                'access'      => CapabilityAccess::normalize((string) ($def['access'] ?? '')),
                'plugin'      => (string) ($def['plugin'] ?? $this->inferPlugin($ident)),
            ];
        }

        return $descriptors;
    }

    /**
     * 收集 Skill 权限
     *
     * @return array<string, array{name:string,description:string,access:string,plugin:string}>
     */
    protected function collectSkills(AccessResolver $resolver): array
    {
        $skills = [];

        try {
            $registry = app(SkillRegistry::class);

            foreach ($registry->all() as $skill) {
                if (!$skill instanceof \wise\agent\skill\contract\SkillInterface) {
                    continue;
                }

                $descriptor = $resolver->resolveSkill($skill);

                if (!$descriptor->requiresPermission()) {
                    continue;
                }

                $skills[$descriptor->permission] = [
                    'name'        => '技能：' . $descriptor->name,
                    'description' => $this->summarize($descriptor->description),
                    'access'      => $descriptor->access,
                    'plugin'      => $descriptor->plugin,
                ];
            }
        } catch (\Throwable) {
        }

        return $skills;
    }

    /**
     * 生成插件级兼容聚合标识（plugin:{ident}.auto.*）
     *
     * @param array $details 能力明细
     * @return array<string, array>
     */
    protected function collectPluginAggregates(array $details): array
    {
        $aggregates = [];

        foreach (array_keys($details) as $ident) {
            if (!preg_match('#^plugin:([^.]+)\.auto\.#', $ident, $m)) {
                continue;
            }

            $pluginIdent = $m[1];
            $aggregate   = 'plugin:' . $pluginIdent . '.auto.*';

            if (isset($aggregates[$aggregate]) || isset($details[$aggregate])) {
                continue;
            }

            $aggregates[$aggregate] = [
                'name'        => '插件 ' . $pluginIdent . ' · 全部自动工具',
                'description' => '一次性授予插件 ' . $pluginIdent . ' 自动生成的全部工具（含后续新增表）。',
                'plugin'      => $pluginIdent,
            ];
        }

        return $aggregates;
    }

    /**
     * 从 ProviderCache 恢复插件工具到 ToolRegistry
     *
     * 仅当缓存已存在时走「缓存恢复」分支（不触发文件系统扫描 / Schema 内省），
     * 使得插件工具声明的权限标识也能被自动发现。缓存不存在时直接返回，
     * 由 `php think wise:agent:discover` 负责首次生成。
     */
    protected function hydrateFromCache(): void
    {
        try {
            $cache = app(\wise\agent\bridge\ProviderCache::class);
            if ($cache->get() === null) {
                return;
            }

            app(\wise\agent\bridge\PluginAiDiscovery::class)->discover(false);
        } catch (\Throwable) {
        }
    }

    /**
     * 从扩展点收集权限定义
     *
     * provider 可以是实现了 collect(): array 的类名 / 对象，或返回数组的闭包。
     *
     * @return array<string, array>
     */
    protected function collectFromExtPoint(): array
    {
        if (!class_exists(\wise\support\ExtPoint::class)) {
            return [];
        }

        $items = [];

        try {
            $providers = \wise\support\ExtPoint::all(self::EXT_POINT);
        } catch (\Throwable) {
            return [];
        }

        foreach ($providers as $provider) {
            if (isset($provider['active']) && !$provider['active']) {
                continue;
            }

            try {
                $concrete = $provider['concrete'] ?? null;

                if (is_string($concrete)) {
                    if (!class_exists($concrete)) {
                        continue;
                    }
                    $instance = app($concrete);
                    $provided = method_exists($instance, 'collect') ? (array) $instance->collect() : [];
                } elseif (is_callable($concrete)) {
                    $value    = call_user_func($concrete);
                    $provided = is_object($value) && method_exists($value, 'collect')
                        ? (array) $value->collect()
                        : (array) $value;
                } else {
                    continue;
                }

                foreach ($provided as $ident => $def) {
                    if (!is_string($ident) || $ident === '' || isset($items[$ident])) {
                        continue;
                    }
                    $items[$ident] = is_array($def) ? $def : ['name' => (string) $def];
                }
            } catch (\Throwable $e) {
                \wise\support\context\ContextualLogger::channel('ai')->warning(
                    '[ToolPermissionRegistrar] Permission provider failed: ' . $e->getMessage()
                );
            }
        }

        return $items;
    }


    /**
     * 构造权限定义（自动分配递增排序号，保证父先子后）
     */
    protected function def(
        string $ident,
        string $name,
        string $description = '',
        int|string $parent = 0,
        string $plugin = self::PLUGIN
    ): array {
        $definition = [
            'ident'       => $ident,
            'name'        => mb_substr($name, 0, 100),
            'type'        => self::TYPE,
            'description' => $this->summarize($description),
            'plugin'      => $plugin,
            'status'      => 1,
            'sort'        => $this->sort++,
        ];

        if (is_string($parent) && $parent !== '') {
            $definition['parent'] = $parent;
        } else {
            $definition['parent_id'] = (int) $parent;
        }

        return $definition;
    }

    /**
     * 软删除本包托管但已不再收集的 ai.* 权限
     *
     * 安全约束：只处理 `plugin = wise-agent` 且 ident 以 `ai.` 开头的记录，
     * 不会触碰插件自有的 `plugin:*` 权限。
     *
     * @param string[] $keepIdents 当前保留的 ident 列表
     * @param array    $result     结果累加（引用）
     */
    protected function pruneStale(array $keepIdents, array &$result): int
    {
        $deleted = 0;

        try {
            $rows = \wise\auth\facade\Perm::getActiveList(['id', 'ident', 'plugin']);
        } catch (\Throwable) {
            return 0;
        }

        foreach ($rows as $row) {
            $ident  = (string) ($row['ident'] ?? '');
            $plugin = (string) ($row['plugin'] ?? '');

            if ($ident === '' || $plugin !== self::PLUGIN || !str_starts_with($ident, 'ai.')) {
                continue;
            }

            if (in_array($ident, $keepIdents, true)) {
                continue;
            }

            try {
                if (\wise\auth\facade\Perm::deletePermission($ident)) {
                    $deleted++;
                    $result['items'][] = $ident . ' (pruned)';
                }
            } catch (\Throwable $e) {
                $result['errors'][] = "prune {$ident}: " . $e->getMessage();
            }
        }

        return $deleted;
    }

    /**
     * 由权限标识推断归属插件
     */
    protected function inferPlugin(string $permission): string
    {
        if (str_starts_with($permission, 'plugin:')) {
            $rest  = substr($permission, 7);
            $ident = explode('.', $rest)[0] ?? '';

            return $ident !== '' ? $ident : self::PLUGIN;
        }

        return self::PLUGIN;
    }

    /**
     * 截断描述，避免超出 auth_permissions.description(255) 长度
     */
    protected function summarize(string $description): string
    {
        $description = trim(preg_replace('/\s+/', ' ', $description) ?? '');

        return mb_substr($description, 0, 200);
    }
}
