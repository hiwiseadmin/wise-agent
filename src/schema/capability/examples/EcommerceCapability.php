<?php
declare(strict_types=1);

namespace wise\agent\schema\capability\examples;

use wise\agent\contract\ToolInterface;
use wise\agent\schema\capability\BusinessCapability;
use wise\agent\schema\dto\FieldInfo;
use wise\agent\schema\dto\PluginSchemaDTO;
use wise\agent\schema\dto\TableInfo;

/**
 * 电商运营业务能力（自定义注册示例）
 *
 * 通过 config/wise-agent.php 的 schema.capabilities 注册。
 * 展示如何实现自定义 BusinessCapability 供第三方使用。
 *
 * 注册方式：
 *   // config/wise-agent.php
 *   'schema' => [
 *       'capabilities' => [
 *           'ecommerce' => \wise\agent\schema\capability\examples\EcommerceCapability::class,
 *       ],
 *   ],
 *
 * 使用方式（插件 manifest.json）：
 *   "business_capabilities": ["ecommerce"]
 */
class EcommerceCapability extends BusinessCapability
{
    public function getTag(): string
    {
        return 'ecommerce';
    }

    public function getRequiredTables(): array
    {
        return [
            'order'  => ['order', 'orders', 'sale_order', 'sales_order'],
            'refund' => ['refund', 'return', 'after_sale', 'refund_order'],
        ];
    }

    public function getContextPrompt(PluginSchemaDTO $schema): string
    {
        return <<<'PROMPT'

## 电商运营业务知识

你是一个专业的电商运营 AI 助手。你需要理解以下业务概念：

### 核心概念
- **订单 (Order)**：用户提交的购买请求，包含商品、数量、金额、收货地址
- **SKU (Stock Keeping Unit)**：最小库存管理单位，同一商品的不同规格为不同 SKU
- **退款 (Refund)**：用户退货后系统返还金额，分全额退款和部分退款
- **物流 (Logistics)**：从仓库发货到用户签收的运输过程
- **购物车 (Cart)**：用户暂存的待购买商品集合

### 关键指标
- **GMV (Gross Merchandise Volume)**：总成交金额（含未付款）
- **客单价** = GMV / 成交订单数
- **转化率** = 下单用户数 / 访问用户数 × 100%
- **退款率** = 退款金额 / 成交金额 × 100%
- **复购率** = 重复购买用户数 / 总购买用户数 × 100%

### 分析维度
1. 商品维度：热销排行、滞销分析、品类 GMV 占比
2. 用户维度：新老客占比、客单价分层、购买频次分布
3. 订单维度：订单状态分布、支付方式分析、退款原因分析
4. 物流维度：平均发货时长、签收率、异常物流占比

PROMPT;
    }

    public function getTools(PluginSchemaDTO $schema): array
    {
        $tools = [];

        $orderTable  = $this->findTable($schema, ['order', 'orders', 'sale_order']);
        $refundTable = $this->findTable($schema, ['refund', 'return', 'after_sale']);

        if ($orderTable !== null) {
            $tools[] = new OrderAnalysisTool($orderTable);
        }

        if ($refundTable !== null) {
            $tools[] = new RefundAnalysisTool($refundTable);
        }

        return $tools;
    }

    public function getRecommendedSkills(): array
    {
        return [
            'data.analyze',
            'data.trend_predict',
            'data.report_generate',
            'data.anomaly_detect',
        ];
    }

    public function getRecommendedPipelines(PluginSchemaDTO $schema): array
    {
        return [
            'sales_report_daily' => [
                'name'        => '每日销售报告',
                'description' => '生成每日销售分析报告（GMV/订单数/客单价/退款率）',
                'steps'       => [
                    ['skill' => 'data.analyze', 'input_mapping' => ['query' => '分析今日销售数据：GMV、订单数、客单价、退款率、热销商品 TOP10']],
                    ['skill' => 'data.report_generate', 'input_mapping' => ['analysis' => '$steps.analysis.result']],
                ],
            ],
            'inventory_alert' => [
                'name'        => '库存预警',
                'description' => '识别低库存热销商品，生成补货提醒',
                'steps'       => [
                    ['skill' => 'data.analyze', 'input_mapping' => ['query' => '识别近7天销量TOP50中库存低于安全线的商品']],
                ],
            ],
        ];
    }
}


/**
 * 订单分析工具
 */
class OrderAnalysisTool implements ToolInterface
{
    /** @var TableInfo */
    private $orderTable;

    public function __construct(TableInfo $orderTable)
    {
        $this->orderTable = $orderTable;
    }

    public function getName(): string
    {
        return 'ecommerce_order_analysis';
    }

    public function getDescription(): string
    {
        return '分析订单数据。支持 GMV 统计、订单状态分布、支付方式分析。';
    }

    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'analysis_type' => [
                    'type'        => 'string',
                    'enum'        => ['gmv', 'status', 'payment'],
                    'description' => '分析类型：gmv=成交额统计, status=状态分布, payment=支付分析',
                    'default'     => 'gmv',
                ],
                'start_date' => [
                    'type'        => 'string',
                    'description' => '开始日期（YYYY-MM-DD）',
                ],
                'end_date' => [
                    'type'        => 'string',
                    'description' => '结束日期（YYYY-MM-DD）',
                ],
            ],
        ];
    }

    public function execute(array $arguments): string
    {
        try {
            $analysisType = $arguments['analysis_type'] ?? 'gmv';
            $startDate    = $arguments['start_date'] ?? null;
            $endDate      = $arguments['end_date'] ?? null;

            $query = \think\facade\Db::name($this->orderTable->name);

            if ($startDate !== null) {
                $timeField = $this->findTimeField();
                if ($timeField) {
                    $query->where($timeField, '>=', $startDate . ' 00:00:00');
                }
            }
            if ($endDate !== null) {
                $timeField = $this->findTimeField();
                if ($timeField) {
                    $query->where($timeField, '<=', $endDate . ' 23:59:59');
                }
            }

            $total = $query->count();

            $result = [
                'analysis_type' => $analysisType,
                'total_orders'  => $total,
            ];

            $amountField = $this->findAmountField();
            if ($amountField !== null) {
                $result['gmv']          = round((float) $query->sum($amountField), 2);
                $result['avg_order']    = $total > 0 ? round((float) $query->avg($amountField), 2) : 0;
                $result['max_order']    = (float) $query->max($amountField);
                $result['min_order']    = (float) $query->min($amountField);
            }

            return json_encode($result, JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    public function getPermission(): string
    {
        return 'plugin:*.ecommerce.order_analysis';
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

    private function findTimeField(): ?string
    {
        foreach (['create_time', 'created_at', 'order_time', 'pay_time'] as $candidate) {
            foreach ($this->orderTable->fields as $f) {
                if ($f instanceof FieldInfo && $f->name === $candidate) return $candidate;
            }
        }
        return null;
    }

    private function findAmountField(): ?string
    {
        foreach (['total_amount', 'amount', 'order_amount', 'pay_amount', 'price'] as $candidate) {
            foreach ($this->orderTable->fields as $f) {
                if ($f instanceof FieldInfo && $f->name === $candidate) return $candidate;
            }
        }
        return null;
    }
}

/**
 * 退款分析工具
 */
class RefundAnalysisTool implements ToolInterface
{
    /** @var TableInfo */
    private $refundTable;

    public function __construct(TableInfo $refundTable)
    {
        $this->refundTable = $refundTable;
    }

    public function getName(): string
    {
        return 'ecommerce_refund_analysis';
    }

    public function getDescription(): string
    {
        return '分析退款数据。统计退款率、退款原因分布、退款金额趋势。';
    }

    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'start_date' => ['type' => 'string', 'description' => '开始日期（YYYY-MM-DD）'],
                'end_date'   => ['type' => 'string', 'description' => '结束日期（YYYY-MM-DD）'],
            ],
        ];
    }

    public function execute(array $arguments): string
    {
        try {
            $startDate = $arguments['start_date'] ?? null;
            $endDate   = $arguments['end_date'] ?? null;

            $query = \think\facade\Db::name($this->refundTable->name);

            if ($startDate !== null) {
                $timeField = $this->findTimeField();
                if ($timeField) {
                    $query->where($timeField, '>=', $startDate . ' 00:00:00');
                }
            }
            if ($endDate !== null) {
                $timeField = $this->findTimeField();
                if ($timeField) {
                    $query->where($timeField, '<=', $endDate . ' 23:59:59');
                }
            }

            $total = $query->count();
            $amountField = $this->findAmountField();

            $result = ['total_refunds' => $total];
            if ($amountField !== null) {
                $result['total_amount'] = round((float) $query->sum($amountField), 2);
            }

            return json_encode($result, JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    public function getPermission(): string
    {
        return 'plugin:*.ecommerce.refund_analysis';
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

    private function findTimeField(): ?string
    {
        foreach (['create_time', 'created_at', 'refund_time', 'apply_time'] as $c) {
            foreach ($this->refundTable->fields as $f) {
                if ($f instanceof FieldInfo && $f->name === $c) return $c;
            }
        }
        return null;
    }

    private function findAmountField(): ?string
    {
        foreach (['refund_amount', 'amount', 'total_amount'] as $c) {
            foreach ($this->refundTable->fields as $f) {
                if ($f instanceof FieldInfo && $f->name === $c) return $c;
            }
        }
        return null;
    }
}
