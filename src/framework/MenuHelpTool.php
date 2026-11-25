<?php
declare(strict_types=1);

namespace wise\agent\framework;

use think\facade\Db;
use wise\agent\contract\ToolInterface;
use wise\agent\security\CapabilityAccess;

/**
 * 后台功能菜单搜索工具
 *
 * 按关键词搜索后台功能菜单，返回匹配菜单的名称、路径与层级，
 * 帮助 AI 定位到具体功能页面，回答"在哪里能找到 XX 功能"。
 *
 * 只返回菜单元数据，不执行任何控制器动作。
 */
class MenuHelpTool implements ToolInterface
{
    public function getName(): string
    {
        return 'wa_menu_help';
    }

    public function getDescription(): string
    {
        return '按关键词搜索后台功能菜单，返回匹配菜单的名称、路径与层级，帮助定位到具体功能页面。';
    }

    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'keyword' => [
                    'type'        => 'string',
                    'description' => '搜索关键词（如"角色"、"订单"）',
                ],
            ],
            'required'   => ['keyword'],
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
     * @inheritDoc 只读：仅检索菜单元数据，不执行任何控制器动作
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
        $keyword = (string) ($arguments['keyword'] ?? '');
        if ($keyword === '') {
            return json_encode(['error' => 'keyword 参数必填'], JSON_UNESCAPED_UNICODE);
        }

        try {
            $rows = Db::name('menus')
                ->where('status', 1)
                ->whereNull('delete_time')
                ->where('name', 'like', '%' . $keyword . '%')
                ->order('sort', 'asc')
                ->limit(20)
                ->select()
                ->toArray();

            $result = [];
            foreach ($rows as $row) {
                $result[] = [
                    'name' => (string) ($row['name'] ?? ''),
                    'path' => (string) ($row['path'] ?? ''),
                    'type' => (string) ($row['type'] ?? 'menu'),
                    'icon' => (string) ($row['icon'] ?? ''),
                ];
            }

            return json_encode([
                'keyword' => $keyword,
                'total'   => count($result),
                'menus'   => $result,
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }
}
