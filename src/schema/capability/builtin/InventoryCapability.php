<?php
declare(strict_types=1);

namespace wise\agent\schema\capability\builtin;

use wise\agent\contract\ToolInterface;
use wise\agent\schema\capability\BusinessCapability;
use wise\agent\schema\dto\FieldInfo;
use wise\agent\schema\dto\PluginSchemaDTO;
use wise\agent\schema\dto\TableInfo;

/**
 * 进销存业务能力（完整实现）
 *
 * 提供库存管理、商品出入库、盘点等 AI 专用能力。
 * 包含 6 个专用 Tool + 领域知识 Context + 推荐 Skills + 推荐 Pipelines。
 *
 * 假设插件至少有以下类型的表：
 *   - 商品表（product/goods/item）
 *   - 库存表（stock/inventory）
 *   - 入库记录表（stock_in/purchase/entry）
 *   - 出库记录表（stock_out/sale/sales/delivery）
 *   - 仓库表（warehouse/store）
 *
 * 表发现机制：通过 findTable() 按候选名自动匹配，
 * 未找到对应表时对应 Tool 不生成。
 */
class InventoryCapability extends BusinessCapability
{
    public function getTag(): string
    {
        return 'inventory';
    }

    public function getRequiredTables(): array
    {
        return [
            'product'   => ['product', 'goods', 'item'],
            'stock'     => ['stock', 'inventory'],
            'stock_in'  => ['stock_in', 'purchase', 'entry', 'warehousing'],
            'stock_out' => ['stock_out', 'sale', 'sales', 'delivery', 'shipment'],
            'warehouse' => ['warehouse', 'store', 'storage'],
        ];
    }


    public function getContextPrompt(PluginSchemaDTO $schema): string
    {
        return <<<'PROMPT'

## 进销存业务知识

你是一个专业的进销存管理 AI 助手。你需要理解以下业务概念：

### 核心概念
- **入库 (Stock In)**：商品进入仓库，库存增加。包括采购入库、退货入库、调拨入库。
- **出库 (Stock Out)**：商品离开仓库，库存减少。包括销售出库、采购退货、领用出库。
- **库存 (Stock/Inventory)**：仓库中当前可用的商品数量。
- **盘点 (Stock Check)**：核对系统库存与实际库存是否一致。
- **安全库存 (Safety Stock)**：为避免缺货而设置的最低库存警戒线。

### 关键指标
- **库存周转率** = 出库总量 / 平均库存量
- **库存天数** = 当前库存 / 日均出库量
- **缺货率** = 缺货 SKU 数 / 总 SKU 数
- **滞销率** = 180 天未出库 SKU / 总 SKU 数

### 分析建议维度
1. 低库存预警：库存低于安全库存的商品，建议立即补货
2. 滞销分析：长期未出库的商品，建议促销或清仓
3. 畅销排行：按出库量排序，辅助采购决策
4. 库存健康度：综合评估库存结构是否合理
5. 盘点差异：对比系统库存与实际库存，标记差异商品

PROMPT;
    }


    /**
     * @inheritDoc
     */
    public function getTools(PluginSchemaDTO $schema): array
    {
        $tools = [];

        $productTable  = $this->findTable($schema, ['product', 'goods', 'item']);
        $stockTable    = $this->findTable($schema, ['stock', 'inventory']);
        $stockInTable  = $this->findTable($schema, ['stock_in', 'purchase', 'entry', 'warehousing']);
        $stockOutTable = $this->findTable($schema, ['stock_out', 'sale', 'sales', 'delivery', 'shipment']);

        if ($stockTable !== null && $productTable !== null) {
            $tools[] = new LowStockAlertTool($stockTable, $productTable);
        }

        if ($stockTable !== null) {
            $tools[] = new StockAnalysisTool($stockTable);
        }

        if ($stockInTable !== null && $stockTable !== null) {
            $tools[] = new StockInTool($stockInTable, $stockTable);
        }

        if ($stockOutTable !== null && $stockTable !== null) {
            $tools[] = new StockOutTool($stockOutTable, $stockTable);
        }

        if ($stockTable !== null) {
            $tools[] = new StockCheckTool($stockTable);
        }

        if ($stockInTable !== null || $stockOutTable !== null) {
            $tools[] = new StockFlowQueryTool($schema, $stockInTable, $stockOutTable);
        }

        return $tools;
    }


    public function getRecommendedSkills(): array
    {
        return [
            'data.analyze',
            'data.trend_predict',
            'data.report_generate',
        ];
    }

    public function getRecommendedPipelines(PluginSchemaDTO $schema): array
    {
        return [
            'inventory_health_report' => [
                'name'        => '库存健康度报告',
                'description' => '分析库存状态，生成健康度报告',
                'steps'       => [
                    [
                        'skill'         => 'data.analyze',
                        'input_mapping' => [
                            'query' => '分析当前库存数据：低库存预警、滞销商品、库存周转率',
                        ],
                    ],
                    [
                        'skill'         => 'data.report_generate',
                        'input_mapping' => [
                            'analysis' => '$steps.analysis.result',
                        ],
                    ],
                ],
            ],
            'restock_suggestion' => [
                'name'        => '智能补货建议',
                'description' => '基于历史销售数据和当前库存，生成补货建议',
                'steps'       => [
                    [
                        'skill'         => 'data.analyze',
                        'input_mapping' => [
                            'query' => '分析近 30 天出库趋势，识别补货需求商品',
                        ],
                    ],
                    [
                        'skill'         => 'data.trend_predict',
                        'input_mapping' => [
                            'data' => '$steps.analysis.data',
                        ],
                    ],
                ],
            ],
            'purchase_plan' => [
                'name'        => '采购计划建议',
                'description' => '根据库存预警和销售趋势，生成采购计划',
                'steps'       => [
                    [
                        'skill'         => 'data.analyze',
                        'input_mapping' => [
                            'query' => '分析低库存预警商品和近 30 天出库数据，计算建议采购量',
                        ],
                    ],
                    [
                        'skill'         => 'data.report_generate',
                        'input_mapping' => [
                            'analysis' => '$steps.analysis.result',
                        ],
                    ],
                ],
            ],
        ];
    }
}


/**
 * 低库存预警工具
 *
 * 查询库存量低于安全库存警戒线的商品，返回预警列表。
 */
class LowStockAlertTool implements ToolInterface
{
    /** @var TableInfo */
    private $stockTable;
    /** @var TableInfo */
    private $productTable;

    public function __construct(TableInfo $stockTable, TableInfo $productTable)
    {
        $this->stockTable   = $stockTable;
        $this->productTable = $productTable;
    }

    public function getName(): string
    {
        return 'inventory_low_stock_alert';
    }

    public function getDescription(): string
    {
        return '查询低库存预警商品。返回所有库存量低于安全库存警戒线的商品列表，按紧急程度排序。';
    }

    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'warehouse_id' => [
                    'type'        => 'integer',
                    'description' => '指定仓库 ID（可选，不传则查询所有仓库）',
                ],
            ],
        ];
    }

    public function execute(array $arguments): string
    {
        try {
            $query = \think\facade\Db::name($this->stockTable->name);

            $quantityField = $this->findField($this->stockTable, ['quantity', 'stock', 'qty', 'amount']);
            $alertField    = $this->findField($this->stockTable, ['min_stock', 'min_quantity', 'alert_stock', 'safety_stock']);
            $productField  = $this->findField($this->stockTable, ['product_id', 'goods_id', 'item_id']);

            if ($quantityField === null) {
                return json_encode(['error' => 'Cannot find quantity field in stock table'], JSON_UNESCAPED_UNICODE);
            }

            $selectFields = ['*'];
            if ($alertField !== null) {
                $query->where($quantityField, '<', \think\facade\Db::raw($alertField));
            } else {
                $query->where($quantityField, '<=', 0);
            }

            if (!empty($arguments['warehouse_id'])) {
                $whField = $this->findField($this->stockTable, ['warehouse_id', 'store_id']);
                if ($whField !== null) {
                    $query->where($whField, $arguments['warehouse_id']);
                }
            }

            $alerts = $query->select();

            $alertsArray = $this->toArray($alerts);
            if ($productField !== null) {
                foreach ($alertsArray as &$alert) {
                    if (isset($alert[$productField])) {
                        try {
                            $product = \think\facade\Db::name($this->productTable->name)
                                ->where('id', $alert[$productField])
                                ->find();
                            if ($product !== null) {
                                $alert['product'] = $this->toArray($product);
                            }
                        } catch (\Throwable $e) {
                        }
                    }
                }
            }

            return json_encode([
                'alert_count' => count($alertsArray),
                'message'     => '发现 ' . count($alertsArray) . ' 个低库存预警商品',
                'alerts'      => $alertsArray,
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    public function getPermission(): string
    {
        return 'plugin:*.inventory.low_stock_alert';
    }

    public function requireConfirmation(): bool
    {
        return false;
    }

    public function getAccess(): string
    {
        return \wise\agent\security\CapabilityAccess::READ;
    }

    public function getRiskLevel(): string
    {
        return \wise\agent\security\CapabilityAccess::RISK_LOW;
    }

    private function findField(TableInfo $table, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            foreach ($table->fields as $field) {
                if ($field instanceof FieldInfo && $field->name === $candidate) {
                    return $candidate;
                }
            }
        }
        return null;
    }

    private function toArray($data): array
    {
        if (is_array($data)) return $data;
        if (is_object($data) && method_exists($data, 'toArray')) return $data->toArray();
        return (array) $data;
    }
}

/**
 * 库存分析工具
 *
 * 分析库存结构：周转率、滞销品、畅销品、库存健康度。
 */
class StockAnalysisTool implements ToolInterface
{
    /** @var TableInfo */
    private $stockTable;

    public function __construct(TableInfo $stockTable)
    {
        $this->stockTable = $stockTable;
    }

    public function getName(): string
    {
        return 'inventory_stock_analysis';
    }

    public function getDescription(): string
    {
        return '分析库存数据。提供库存概况（总量/SKU数/均价）、库存分布、异常检测等分析结果。';
    }

    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'analysis_type' => [
                    'type'        => 'string',
                    'enum'        => ['overview', 'distribution', 'anomaly'],
                    'description' => '分析类型：overview=库存概况, distribution=库存分布, anomaly=异常检测',
                    'default'     => 'overview',
                ],
                'warehouse_id' => [
                    'type'        => 'integer',
                    'description' => '指定仓库 ID（可选）',
                ],
            ],
        ];
    }

    public function execute(array $arguments): string
    {
        try {
            $analysisType = $arguments['analysis_type'] ?? 'overview';
            $query = \think\facade\Db::name($this->stockTable->name);

            if (!empty($arguments['warehouse_id'])) {
                $whField = $this->findField($this->stockTable, ['warehouse_id', 'store_id']);
                if ($whField !== null) {
                    $query->where($whField, $arguments['warehouse_id']);
                }
            }

            switch ($analysisType) {
                case 'overview':
                    return $this->analysisOverview($query);
                case 'distribution':
                    return $this->analysisDistribution($query);
                case 'anomaly':
                    return $this->analysisAnomaly($query);
                default:
                    return json_encode(['error' => 'Unknown analysis_type'], JSON_UNESCAPED_UNICODE);
            }
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    private function analysisOverview($query): string
    {
        $totalRecords = $query->count();
        $quantityField = $this->findField($this->stockTable, ['quantity', 'stock', 'qty', 'amount']);

        $result = ['total_records' => $totalRecords];

        if ($quantityField !== null) {
            $result['total_quantity'] = (float) $query->sum($quantityField);
            $result['avg_quantity']   = $totalRecords > 0
                ? round((float) $query->avg($quantityField), 2)
                : 0;
            $result['max_quantity']   = (float) $query->max($quantityField);
            $result['min_quantity']   = (float) $query->min($quantityField);

            $zeroStock = (clone $query)->where($quantityField, '<=', 0)->count();
            $result['zero_stock_count'] = $zeroStock;
            $result['zero_stock_rate']  = $totalRecords > 0
                ? round($zeroStock / $totalRecords * 100, 2) . '%'
                : '0%';
        }

        return json_encode($result, JSON_UNESCAPED_UNICODE);
    }

    private function analysisDistribution($query): string
    {
        $whField = $this->findField($this->stockTable, ['warehouse_id', 'store_id']);

        if ($whField === null) {
            return json_encode(['message' => 'No warehouse field found for distribution analysis'], JSON_UNESCAPED_UNICODE);
        }

        $distribution = $query->group($whField)
            ->field($whField . ' as warehouse_id, COUNT(*) as sku_count')
            ->select();

        return json_encode([
            'distribution' => $this->toArray($distribution),
        ], JSON_UNESCAPED_UNICODE);
    }

    private function analysisAnomaly($query): string
    {
        $quantityField = $this->findField($this->stockTable, ['quantity', 'stock', 'qty', 'amount']);

        $result = [];

        if ($quantityField !== null) {
            $negStock = $query->where($quantityField, '<', 0)->select();
            $result['negative_stock'] = $this->toArray($negStock);
            $result['negative_stock_count'] = count($result['negative_stock']);
        }

        return json_encode($result, JSON_UNESCAPED_UNICODE);
    }

    public function getPermission(): string
    {
        return 'plugin:*.inventory.stock_analysis';
    }

    public function requireConfirmation(): bool
    {
        return false;
    }

    public function getAccess(): string
    {
        return \wise\agent\security\CapabilityAccess::READ;
    }

    public function getRiskLevel(): string
    {
        return \wise\agent\security\CapabilityAccess::RISK_LOW;
    }

    private function findField(TableInfo $table, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            foreach ($table->fields as $field) {
                if ($field instanceof FieldInfo && $field->name === $candidate) {
                    return $candidate;
                }
            }
        }
        return null;
    }

    private function toArray($data): array
    {
        if (is_array($data)) return $data;
        if (is_object($data) && method_exists($data, 'toArray')) return $data->toArray();
        return (array) $data;
    }
}

/**
 * 入库操作工具
 */
class StockInTool implements ToolInterface
{
    /** @var TableInfo */
    private $stockInTable;
    /** @var TableInfo */
    private $stockTable;

    public function __construct(TableInfo $stockInTable, TableInfo $stockTable)
    {
        $this->stockInTable = $stockInTable;
        $this->stockTable   = $stockTable;
    }

    public function getName(): string
    {
        return 'inventory_stock_in';
    }

    public function getDescription(): string
    {
        return '执行商品入库操作。创建入库记录，同时更新对应商品的库存数量（增加）。';
    }

    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'product_id' => [
                    'type'        => 'integer',
                    'description' => '商品 ID',
                ],
                'quantity' => [
                    'type'        => 'number',
                    'description' => '入库数量',
                ],
                'warehouse_id' => [
                    'type'        => 'integer',
                    'description' => '仓库 ID',
                ],
                'remark' => [
                    'type'        => 'string',
                    'description' => '入库备注（可选）',
                ],
            ],
            'required' => ['product_id', 'quantity'],
        ];
    }

    public function execute(array $arguments): string
    {
        try {
            $productId    = $arguments['product_id'] ?? 0;
            $quantity     = floatval($arguments['quantity'] ?? 0);
            $warehouseId  = $arguments['warehouse_id'] ?? 0;
            $remark       = $arguments['remark'] ?? '';

            if ($productId <= 0 || $quantity <= 0) {
                return json_encode([
                    'error' => 'product_id 和 quantity 必须大于 0',
                ], JSON_UNESCAPED_UNICODE);
            }

            $inData = [
                'product_id'   => $productId,
                'quantity'     => $quantity,
                'remark'       => $remark,
            ];

            $productField = $this->findField($this->stockInTable, ['create_time', 'created_at', 'date', 'time']);
            if ($productField !== null) {
                $outData[$productField] = date('Y-m-d H:i:s');
            }

            $productField = $this->findField($this->stockInTable, ['product_id', 'goods_id', 'item_id']);
            if ($productField !== null) {
                $inData[$productField] = $productId;
            }

            $qtyField = $this->findField($this->stockInTable, ['quantity', 'qty', 'amount', 'num']);
            if ($qtyField !== null && $qtyField !== 'product_id') {
                $inData[$qtyField] = $quantity;
            }

            if ($warehouseId > 0) {
                $whField = $this->findField($this->stockInTable, ['warehouse_id', 'store_id']);
                if ($whField !== null) {
                    $inData[$whField] = $warehouseId;
                }
            }

            $stockInId = \think\facade\Db::name($this->stockInTable->name)->insertGetId($inData);

            $this->updateStockQuantity($productId, $quantity, $warehouseId);

            return json_encode([
                'success'      => true,
                'message'      => '入库成功',
                'stock_in_id'  => $stockInId,
                'product_id'   => $productId,
                'quantity'     => $quantity,
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    private function updateStockQuantity($productId, float $quantity, $warehouseId): void
    {
        $query = \think\facade\Db::name($this->stockTable->name);
        $productField = $this->findField($this->stockTable, ['product_id', 'goods_id', 'item_id']);
        $qtyField = $this->findField($this->stockTable, ['quantity', 'stock', 'qty', 'amount']);

        if ($productField === null || $qtyField === null) {
            return;
        }

        $query->where($productField, $productId);

        if ($warehouseId > 0) {
            $whField = $this->findField($this->stockTable, ['warehouse_id', 'store_id']);
            if ($whField !== null) {
                $query->where($whField, $warehouseId);
            }
        }

        $query->inc($qtyField, $quantity)->update();
    }

    public function getPermission(): string
    {
        return 'plugin:*.inventory.stock_in';
    }

    public function requireConfirmation(): bool
    {
        return true;
    }

    public function getAccess(): string
    {
        return \wise\agent\security\CapabilityAccess::WRITE;
    }

    public function getRiskLevel(): string
    {
        return \wise\agent\security\CapabilityAccess::RISK_MEDIUM;
    }

    private function findField(TableInfo $table, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            foreach ($table->fields as $field) {
                if ($field instanceof FieldInfo && $field->name === $candidate) {
                    return $candidate;
                }
            }
        }
        return null;
    }
}

/**
 * 出库操作工具
 */
class StockOutTool implements ToolInterface
{
    /** @var TableInfo */
    private $stockOutTable;
    /** @var TableInfo */
    private $stockTable;

    public function __construct(TableInfo $stockOutTable, TableInfo $stockTable)
    {
        $this->stockOutTable = $stockOutTable;
        $this->stockTable    = $stockTable;
    }

    public function getName(): string
    {
        return 'inventory_stock_out';
    }

    public function getDescription(): string
    {
        return '执行商品出库操作。创建出库记录，同时减少对应商品的库存数量。';
    }

    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'product_id' => [
                    'type'        => 'integer',
                    'description' => '商品 ID',
                ],
                'quantity' => [
                    'type'        => 'number',
                    'description' => '出库数量',
                ],
                'warehouse_id' => [
                    'type'        => 'integer',
                    'description' => '仓库 ID',
                ],
                'remark' => [
                    'type'        => 'string',
                    'description' => '出库备注（可选）',
                ],
            ],
            'required' => ['product_id', 'quantity'],
        ];
    }

    public function execute(array $arguments): string
    {
        try {
            $productId   = $arguments['product_id'] ?? 0;
            $quantity    = floatval($arguments['quantity'] ?? 0);
            $warehouseId = $arguments['warehouse_id'] ?? 0;
            $remark      = $arguments['remark'] ?? '';

            if ($productId <= 0 || $quantity <= 0) {
                return json_encode([
                    'error' => 'product_id 和 quantity 必须大于 0',
                ], JSON_UNESCAPED_UNICODE);
            }

            $decreaseResult = $this->atomicDecreaseStock($productId, $quantity, $warehouseId);
            if (!$decreaseResult['success']) {
                return json_encode([
                    'error'   => 'Insufficient stock',
                    'message' => "库存不足。当前库存: {$decreaseResult['current_stock']}，需要出库: {$quantity}",
                ], JSON_UNESCAPED_UNICODE);
            }

            $outData = [
                'product_id' => $productId,
                'quantity'   => $quantity,
                'remark'     => $remark,
            ];

            $productField = $this->findField($this->stockOutTable, ['create_time', 'created_at', 'date', 'time']);
            if ($productField !== null) {
                $outData[$productField] = date('Y-m-d H:i:s');
            }

            $productField = $this->findField($this->stockOutTable, ['product_id', 'goods_id', 'item_id']);
            if ($productField !== null) {
                $outData[$productField] = $productId;
            }

            $qtyField = $this->findField($this->stockOutTable, ['quantity', 'qty', 'amount', 'num']);
            if ($qtyField !== null && $qtyField !== 'product_id') {
                $outData[$qtyField] = $quantity;
            }

            if ($warehouseId > 0) {
                $whField = $this->findField($this->stockOutTable, ['warehouse_id', 'store_id']);
                if ($whField !== null) {
                    $outData[$whField] = $warehouseId;
                }
            }

            $stockOutId = \think\facade\Db::name($this->stockOutTable->name)->insertGetId($outData);

            return json_encode([
                'success'       => true,
                'message'       => '出库成功',
                'stock_out_id'  => $stockOutId,
                'product_id'    => $productId,
                'quantity'      => $quantity,
                'remaining'     => $decreaseResult['remaining'],
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            return json_encode(['error' => 'An internal error occurred during stock-out operation.'], JSON_UNESCAPED_UNICODE);
        }
    }

    private function getCurrentStock($productId, $warehouseId): float
    {
        $query = \think\facade\Db::name($this->stockTable->name);
        $productField = $this->findField($this->stockTable, ['product_id', 'goods_id', 'item_id']);
        $qtyField = $this->findField($this->stockTable, ['quantity', 'stock', 'qty', 'amount']);

        if ($productField === null || $qtyField === null) {
            return 0;
        }

        $query->where($productField, $productId);

        if ($warehouseId > 0) {
            $whField = $this->findField($this->stockTable, ['warehouse_id', 'store_id']);
            if ($whField !== null) {
                $query->where($whField, $warehouseId);
            }
        }

        return (float) $query->value($qtyField);
    }

    /**
     * 原子扣减库存 — 使用数据库条件更新防止并发超卖
     *
     * 仅在当前库存 >= 扣减数量时执行 dec 更新，通过 affected rows 判断是否成功。
     *
     * @param int $productId
     * @param float $quantity
     * @param int $warehouseId
     * @return array ['success' => bool, 'current_stock' => float, 'remaining' => float]
     */
    private function atomicDecreaseStock($productId, float $quantity, $warehouseId): array
    {
        $query = \think\facade\Db::name($this->stockTable->name);
        $productField = $this->findField($this->stockTable, ['product_id', 'goods_id', 'item_id']);
        $qtyField = $this->findField($this->stockTable, ['quantity', 'stock', 'qty', 'amount']);

        if ($productField === null || $qtyField === null) {
            $stockQty = $this->getCurrentStock($productId, $warehouseId);
            if ($stockQty < $quantity) {
                return ['success' => false, 'current_stock' => $stockQty, 'remaining' => $stockQty];
            }
            $this->decreaseStockQuantity($productId, $quantity, $warehouseId);
            return ['success' => true, 'current_stock' => $stockQty, 'remaining' => $stockQty - $quantity];
        }

        $query->where($productField, $productId);
        $query->where($qtyField, '>=', $quantity);

        if ($warehouseId > 0) {
            $whField = $this->findField($this->stockTable, ['warehouse_id', 'store_id']);
            if ($whField !== null) {
                $query->where($whField, $warehouseId);
            }
        }

        $affected = $query->dec($qtyField, $quantity)->update();

        if ($affected > 0) {
            $remaining = $this->getCurrentStock($productId, $warehouseId);
            return ['success' => true, 'current_stock' => $remaining + $quantity, 'remaining' => $remaining];
        }

        $currentStock = $this->getCurrentStock($productId, $warehouseId);
        return ['success' => false, 'current_stock' => $currentStock, 'remaining' => $currentStock];
    }

    public function getPermission(): string
    {
        return 'plugin:*.inventory.stock_out';
    }

    public function requireConfirmation(): bool
    {
        return true;
    }

    public function getAccess(): string
    {
        return \wise\agent\security\CapabilityAccess::WRITE;
    }

    public function getRiskLevel(): string
    {
        return \wise\agent\security\CapabilityAccess::RISK_HIGH;
    }

    private function findField(TableInfo $table, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            foreach ($table->fields as $field) {
                if ($field instanceof FieldInfo && $field->name === $candidate) {
                    return $candidate;
                }
            }
        }
        return null;
    }
}

/**
 * 盘点工具
 *
 * 核对系统库存与实际库存，标记差异。
 */
class StockCheckTool implements ToolInterface
{
    /** @var TableInfo */
    private $stockTable;

    public function __construct(TableInfo $stockTable)
    {
        $this->stockTable = $stockTable;
    }

    public function getName(): string
    {
        return 'inventory_stock_check';
    }

    public function getDescription(): string
    {
        return '获取当前系统库存数据，用于盘点核对。返回所有商品在各仓库的库存信息，供人工核对实际库存。';
    }

    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'warehouse_id' => [
                    'type'        => 'integer',
                    'description' => '指定仓库 ID（可选，不传则查询所有仓库）',
                ],
                'page' => [
                    'type'    => 'integer',
                    'default' => 1,
                ],
                'page_size' => [
                    'type'    => 'integer',
                    'default' => 50,
                ],
            ],
        ];
    }

    public function execute(array $arguments): string
    {
        try {
            $page     = max(1, (int) ($arguments['page'] ?? 1));
            $pageSize = min(200, max(1, (int) ($arguments['page_size'] ?? 50)));

            $query = \think\facade\Db::name($this->stockTable->name);

            if (!empty($arguments['warehouse_id'])) {
                $whField = $this->findField($this->stockTable, ['warehouse_id', 'store_id']);
                if ($whField !== null) {
                    $query->where($whField, $arguments['warehouse_id']);
                }
            }

            $total = $query->count();
            $list  = $query->page($page, $pageSize)->select();

            return json_encode([
                'total'       => $total,
                'page'        => $page,
                'page_size'   => $pageSize,
                'message'     => "共计 {$total} 条库存记录待盘点核对",
                'list'        => $this->toArray($list),
                'instruction' => '请将以上数据与实际库存逐一核对，如发现差异请记录 product_id、仓库、差异数量。',
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    public function getPermission(): string
    {
        return 'plugin:*.inventory.stock_check';
    }

    public function requireConfirmation(): bool
    {
        return false;
    }

    public function getAccess(): string
    {
        return \wise\agent\security\CapabilityAccess::READ;
    }

    public function getRiskLevel(): string
    {
        return \wise\agent\security\CapabilityAccess::RISK_LOW;
    }

    private function findField(TableInfo $table, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            foreach ($table->fields as $field) {
                if ($field instanceof FieldInfo && $field->name === $candidate) {
                    return $candidate;
                }
            }
        }
        return null;
    }

    private function toArray($data): array
    {
        if (is_array($data)) return $data;
        if (is_object($data) && method_exists($data, 'toArray')) return $data->toArray();
        return (array) $data;
    }
}

/**
 * 库存流水查询工具
 *
 * 查询商品入库/出库的流水记录。
 */
class StockFlowQueryTool implements ToolInterface
{
    /** @var PluginSchemaDTO */
    private $schema;
    /** @var TableInfo|null */
    private $stockInTable;
    /** @var TableInfo|null */
    private $stockOutTable;

    public function __construct(PluginSchemaDTO $schema, ?TableInfo $stockInTable, ?TableInfo $stockOutTable)
    {
        $this->schema        = $schema;
        $this->stockInTable  = $stockInTable;
        $this->stockOutTable = $stockOutTable;
    }

    public function getName(): string
    {
        return 'inventory_stock_flow';
    }

    public function getDescription(): string
    {
        return '查询商品库存流水。支持按商品、仓库、日期范围、流水类型（入库/出库）筛选。';
    }

    public function getParameters(): array
    {
        $flowTypes = [];
        if ($this->stockInTable !== null)  $flowTypes[] = 'in';
        if ($this->stockOutTable !== null) $flowTypes[] = 'out';

        $params = [
            'type'       => 'object',
            'properties' => [
                'product_id' => [
                    'type'        => 'integer',
                    'description' => '商品 ID（可选）',
                ],
                'flow_type' => [
                    'type'        => 'string',
                    'enum'        => $flowTypes,
                    'description' => '流水类型：in=入库, out=出库',
                ],
                'start_date' => [
                    'type'        => 'string',
                    'description' => '开始日期，格式 YYYY-MM-DD（可选）',
                ],
                'end_date' => [
                    'type'        => 'string',
                    'description' => '结束日期，格式 YYYY-MM-DD（可选）',
                ],
                'page' => [
                    'type'    => 'integer',
                    'default' => 1,
                ],
                'page_size' => [
                    'type'    => 'integer',
                    'default' => 20,
                ],
            ],
        ];

        return $params;
    }

    public function execute(array $arguments): string
    {
        try {
            $productId  = $arguments['product_id'] ?? null;
            $flowType   = $arguments['flow_type'] ?? null;
            $startDate  = $arguments['start_date'] ?? null;
            $endDate    = $arguments['end_date'] ?? null;
            $page       = max(1, (int) ($arguments['page'] ?? 1));
            $pageSize   = min(100, max(1, (int) ($arguments['page_size'] ?? 20)));

            $results = [];

            if ($this->stockInTable !== null && ($flowType === null || $flowType === 'in')) {
                $inQuery = \think\facade\Db::name($this->stockInTable->name);
                $this->applyFilters($inQuery, $this->stockInTable, $productId, $startDate, $endDate);

                $inTotal = $inQuery->count();
                $inList  = $inQuery->order('id', 'desc')->page($page, $pageSize)->select();

                $results['in_flow'] = [
                    'total' => $inTotal,
                    'list'  => $this->toArray($inList),
                ];
            }

            if ($this->stockOutTable !== null && ($flowType === null || $flowType === 'out')) {
                $outQuery = \think\facade\Db::name($this->stockOutTable->name);
                $this->applyFilters($outQuery, $this->stockOutTable, $productId, $startDate, $endDate);

                $outTotal = $outQuery->count();
                $outList  = $outQuery->order('id', 'desc')->page($page, $pageSize)->select();

                $results['out_flow'] = [
                    'total' => $outTotal,
                    'list'  => $this->toArray($outList),
                ];
            }

            $results['filters'] = [
                'product_id' => $productId,
                'flow_type'  => $flowType,
                'start_date' => $startDate,
                'end_date'   => $endDate,
            ];

            return json_encode($results, JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    private function applyFilters($query, TableInfo $table, $productId, $startDate, $endDate): void
    {
        if ($productId !== null) {
            $productField = $this->findField($table, ['product_id', 'goods_id', 'item_id']);
            if ($productField !== null) {
                $query->where($productField, $productId);
            }
        }

        if ($startDate !== null) {
            $timeField = $this->findField($table, ['create_time', 'created_at', 'date', 'time']);
            if ($timeField !== null) {
                $query->where($timeField, '>=', $startDate . ' 00:00:00');
            }
        }

        if ($endDate !== null) {
            $timeField = $this->findField($table, ['create_time', 'created_at', 'date', 'time']);
            if ($timeField !== null) {
                $query->where($timeField, '<=', $endDate . ' 23:59:59');
            }
        }
    }

    public function getPermission(): string
    {
        return 'plugin:*.inventory.stock_flow';
    }

    public function requireConfirmation(): bool
    {
        return false;
    }

    public function getAccess(): string
    {
        return \wise\agent\security\CapabilityAccess::READ;
    }

    public function getRiskLevel(): string
    {
        return \wise\agent\security\CapabilityAccess::RISK_LOW;
    }

    private function findField(TableInfo $table, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            foreach ($table->fields as $field) {
                if ($field instanceof FieldInfo && $field->name === $candidate) {
                    return $candidate;
                }
            }
        }
        return null;
    }

    private function toArray($data): array
    {
        if (is_array($data)) return $data;
        if (is_object($data) && method_exists($data, 'toArray')) return $data->toArray();
        return (array) $data;
    }
}
