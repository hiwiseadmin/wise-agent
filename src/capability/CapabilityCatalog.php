<?php
declare(strict_types=1);

namespace wise\agent\capability;

use wise\agent\contract\ToolInterface;
use wise\agent\security\CapabilityAccess;
use wise\agent\tool\ToolRegistry;

/**
 * 能力目录（B-1）
 *
 * 解决的问题
 * ----------
 * 改造前没有人能回答「这套系统上 AI 到底能做哪些事」—— 工具散落在内置注册、
 * 插件声明、schema 自动生成、MCP 外部导入四条通道，注册表只提供 `list()`，
 * 给不出能力语义（谁能调、风险多高、默认开没开）。
 *
 * 而交付商签合同前必然会问这句话。因此把注册表归一成一份**可导出的能力清单**。
 *
 * 词汇表复用（重要）
 * ------------------
 * access 与 risk **直接复用 `CapabilityAccess`** 的既有取值
 * （read/write/execute/admin、low/medium/high），不另造一套。
 * B-3 里已经吃够"同一概念两套词汇"的苦，这里不再重复。
 *
 * 关于"默认关闭"
 * --------------
 * 不新造 destructive / external 等级，而是用两个既有信号推导出
 * `default_enabled`：admin 级或 high 风险或外部接入（出网）→ 默认关闭。
 * 这样分级只增字段、不增枚举，与现有权限树天然对齐。
 *
 * @package wise\agent\capability
 */
final class CapabilityCatalog
{
    /**
     * 构建能力目录
     *
     * @param ToolRegistry|null $registry 目标注册表（默认全局单例）
     * @return array<int, array{
     *   name:string, namespace:string, source:string, access:string, risk:string,
     *   permission:string, confirm:bool, external:bool, default_enabled:bool,
     *   description:string, priority:int
     * }>
     */
    public static function build(?ToolRegistry $registry = null): array
    {
        $registry ??= ToolRegistry::instance();

        $items = [];

        foreach ($registry->all() as $name => $tool) {
            $name = (string) $name;

            if (!$tool instanceof ToolInterface) {
                $items[] = self::describeOpaque($name, $registry);
                continue;
            }

            $access     = self::normalizeAccess($tool->getAccess());
            $risk       = self::normalizeRisk($tool->getRiskLevel());
            $permission = trim($tool->getPermission());
            $external   = self::isExternal($permission, $access);

            $items[] = [
                'name'            => $name,
                'namespace'       => $registry->getNamespace($name),
                'source'          => self::resolveSource($registry->getNamespace($name), $name),
                'access'          => $access,
                'risk'            => $risk,
                'permission'      => $permission,
                'confirm'         => $tool->requireConfirmation(),
                'external'        => $external,
                'default_enabled' => !($access === CapabilityAccess::ADMIN || $risk === CapabilityAccess::RISK_HIGH || $external),
                'description'     => $tool->getDescription(),
                'priority'        => $registry->getPriority($name),
            ];
        }

        usort($items, static fn(array $a, array $b) => strcmp($a['name'], $b['name']));

        return $items;
    }

    /**
     * 按维度统计（供命令输出摘要）
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<string, int>
     */
    public static function summary(array $items): array
    {
        $summary = [
            'total'           => count($items),
            'default_disabled' => 0,
        ];

        foreach ($items as $item) {
            if (empty($item['default_enabled'])) {
                $summary['default_disabled']++;
            }

            $access = (string) ($item['access'] ?? 'unknown');
            $summary['access_' . $access] = ($summary['access_' . $access] ?? 0) + 1;

            $source = (string) ($item['source'] ?? 'unknown');
            $summary['source_' . $source] = ($summary['source_' . $source] ?? 0) + 1;
        }

        return $summary;
    }

    /**
     * 无元数据的工具（闭包 / 数组）也登记，保证目录"完整"而非"好看"
     *
     * @return array<string, mixed>
     */
    private static function describeOpaque(string $name, ToolRegistry $registry): array
    {
        $namespace = $registry->getNamespace($name);

        return [
            'name'            => $name,
            'namespace'       => $namespace,
            'source'          => self::resolveSource($namespace, $name),
            'access'          => CapabilityAccess::READ,
            'risk'            => CapabilityAccess::RISK_LOW,
            'permission'      => '',
            'confirm'         => false,
            'external'        => false,
            'default_enabled' => true,
            'description'     => '(无元数据的可调用工具)',
            'priority'        => $registry->getPriority($name),
        ];
    }

    /**
     * 归属来源：用于区分"声明 / 自动生成 / 菜单动作 / 内置"
     */
    private static function resolveSource(string $namespace, string $name): string
    {
        if (str_starts_with($namespace, 'plugin:') || str_starts_with($namespace, 'plugin.')) {
            return 'plugin';
        }

        if (str_starts_with($name, 'ma_')) {
            return 'menu_action';
        }

        if ($namespace === 'builtin' || $namespace === '') {
            return 'builtin';
        }

        return $namespace;
    }

    private static function normalizeAccess(string $access): string
    {
        $access = strtolower(trim($access));

        return in_array($access, CapabilityAccess::ALL, true) ? $access : CapabilityAccess::READ;
    }

    private static function normalizeRisk(string $risk): string
    {
        $risk = strtolower(trim($risk));

        return in_array($risk, [
            CapabilityAccess::RISK_LOW,
            CapabilityAccess::RISK_MEDIUM,
            CapabilityAccess::RISK_HIGH,
        ], true) ? $risk : CapabilityAccess::RISK_MEDIUM;
    }

    /**
     * 是否为外部接入能力（会出网 / 操作框架外目标）
     *
     * 判定依据是 B2-2 引入的 `mcp:{server}` 权限约定，以及 access=admin 的运维型能力。
     */
    private static function isExternal(string $permission, string $access): bool
    {
        if (str_starts_with($permission, 'mcp:')) {
            return true;
        }

        return $access === CapabilityAccess::ADMIN;
    }
}
