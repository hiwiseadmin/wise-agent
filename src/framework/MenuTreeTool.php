<?php
declare(strict_types=1);

namespace wise\agent\framework;

use think\facade\Db;
use wise\agent\contract\ToolInterface;
use wise\agent\security\CapabilityAccess;

/**
 * 后台菜单导航树工具
 *
 * 返回当前系统已启用的后台菜单导航树（目录 + 菜单），
 * 让 AI 理解系统有哪些功能页面及其层级关系，从而引导用户
 * "在哪里能改 XX 配置"等导航类问题。
 *
 * 只返回菜单元数据（名称/路径/层级），不执行任何控制器动作，
 * 因此无数据泄露风险。
 */
class MenuTreeTool implements ToolInterface
{
    public function getName(): string
    {
        return 'wa_menu_tree';
    }

    public function getDescription(): string
    {
        return '获取后台菜单导航树（目录+菜单），用于了解系统有哪些功能页面及层级关系。返回菜单名称、路径与父子结构。';
    }

    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'with_button' => [
                    'type'        => 'boolean',
                    'description' => '是否包含按钮类型菜单，默认 false',
                ],
            ],
        ];
    }

    public function getPermission(): string
    {
        return '';
    }

    public function requireConfirmation(): bool
    {
        return false;
    }

    /**
     * @inheritDoc 只读：仅返回菜单元数据，无数据泄露风险
     */
    public function getAccess(): string
    {
        return CapabilityAccess::READ;
    }

    /**
     * @inheritDoc
     */
    public function getRiskLevel(): string
    {
        return CapabilityAccess::RISK_LOW;
    }

    public function execute(array $arguments): string
    {
        try {
            $withButton = !empty($arguments['with_button']);

            $query = Db::name('menus')
                ->where('status', 1)
                ->whereNull('delete_time');

            if (!$withButton) {
                $query->where('type', '<>', 'button');
            }

            $rows = $query->order('sort', 'asc')->select()->toArray();

            return json_encode([
                'total' => count($rows),
                'menus' => $this->buildTree($rows, 0),
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * 从扁平菜单列表构建树
     *
     * @param array $items
     * @param int   $parentId
     * @return array
     */
    private function buildTree(array $items, int $parentId): array
    {
        $tree = [];
        foreach ($items as $item) {
            $pid = (int) ($item['parent_id'] ?? 0);
            if ($pid !== $parentId) {
                continue;
            }

            $node = [
                'name'  => (string) ($item['name'] ?? ''),
                'path'  => (string) ($item['path'] ?? ''),
                'type'  => (string) ($item['type'] ?? 'menu'),
                'icon'  => (string) ($item['icon'] ?? ''),
            ];

            $children = $this->buildTree($items, (int) ($item['id'] ?? 0));
            if (!empty($children)) {
                $node['children'] = $children;
            }

            $tree[] = $node;
        }
        return $tree;
    }
}
