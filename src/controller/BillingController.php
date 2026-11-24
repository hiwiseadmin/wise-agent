<?php
declare(strict_types=1);

namespace wise\agent\controller;

use think\facade\Db;
use wise\support\context\ContextualLogger;
use wise\support\traits\JsonResponse;

/**
 * 用量仪表盘查询接口＼�验收 10）
 *
 * 数据来自 `ai_usage_daily`（日汇总小表），固定输出「估算值」口径声明与
 * `unpriced_calls`（未定价调用数，让"估算覆盖率"可见）。自包含控制器，权限
 * 复用 `admin.ai_task.view`（任务中心可观测权限，fail-closed）。
 */
class BillingController
{
    use JsonResponse;

    private const PERMISSION_VIEW = 'admin.ai_task.view';

    /** 页面顶部固定的口径声明（验收 10 断言文案存在） */
    private const ESTIMATE_DECLARATION =
        '成本为估算值（单价来自 wise-agent.pricing 配置）：与真实账单可能有差异；unpriced_calls 表示未配置单价的调用数';

    protected \think\Request $request;

    public function __construct()
    {
        $this->request = request();
    }

    /**
     * 近 30 天用量汇总（趋势 + 覆盖率）
     */
    public function summary(): \think\response\Json
    {
        if (!$this->assertCanView()) {
            return $this->error('无权限查看用量仪表盘', [], 1);
        }

        $from = date('Y-m-d', strtotime('-30 days'));

        try {
            $rows = Db::name('ai_usage_daily')
                ->where('day', '>=', $from)
                ->order('day', 'asc')
                ->select()
                ->toArray();
            $rows = is_array($rows) ? $rows : [];
        } catch (\Throwable $e) {
            ContextualLogger::error('[BillingController] summary error: ' . $e->getMessage(), [
                'exception' => get_class($e),
            ]);
            return $this->error('读取用量汇总失败', [], 1);
        }

        $totalCalls    = 0;
        $totalCost     = 0.0;
        $unpricedCalls = 0;
        $byDay         = [];
        $byUser        = [];
        $byModel       = [];
        $byProvider    = [];

        foreach ($rows as $row) {
            $calls = (int) ($row['calls'] ?? 0);
            $cost  = (float) ($row['cost'] ?? 0);
            $unpriced = (int) ($row['unpriced_calls'] ?? 0);

            $totalCalls += $calls;
            $totalCost  += $cost;
            $unpricedCalls += $unpriced;

            $day = (string) ($row['day'] ?? '');
            if ($day !== '') {
                if (!isset($byDay[$day])) {
                    $byDay[$day] = ['day' => $day, 'calls' => 0, 'tokens' => 0, 'cost' => 0.0, 'unpriced_calls' => 0];
                }
                $byDay[$day]['calls']           += $calls;
                $byDay[$day]['tokens']          += (int) ($row['total_tokens'] ?? 0);
                $byDay[$day]['cost']            += $cost;
                $byDay[$day]['unpriced_calls']  += $unpriced;
            }

            $userKey = (string) ($row['user_type'] ?? '') . ':' . (int) ($row['user_id'] ?? 0);
            $byUser[$userKey] = [
                'key'   => $userKey,
                'calls' => (int) ($byUser[$userKey]['calls'] ?? 0) + $calls,
                'cost'  => (float) ($byUser[$userKey]['cost'] ?? 0) + $cost,
            ];

            $model = (string) ($row['model'] ?? '');
            if ($model !== '') {
                $byModel[$model] = [
                    'key'   => $model,
                    'calls' => (int) ($byModel[$model]['calls'] ?? 0) + $calls,
                    'cost'  => (float) ($byModel[$model]['cost'] ?? 0) + $cost,
                ];
            }

            $provider = (string) ($row['provider'] ?? '');
            if ($provider !== '') {
                $byProvider[$provider] = [
                    'key'   => $provider,
                    'calls' => (int) ($byProvider[$provider]['calls'] ?? 0) + $calls,
                    'cost'  => (float) ($byProvider[$provider]['cost'] ?? 0) + $cost,
                ];
            }
        }

        return $this->success('ok', [
            'estimate_declaration' => self::ESTIMATE_DECLARATION,
            'total_calls'          => $totalCalls,
            'total_cost'           => round($totalCost, 6),
            'unpriced_calls'       => $unpricedCalls,
            'by_day'               => array_values($byDay),
            'top_users'            => $this->topN($byUser, 5),
            'top_models'           => $this->topN($byModel, 5),
            'top_providers'        => $this->topN($byProvider, 5),
        ]);
    }

    /**
     * 按调用次数降序取 Top N（calls 相同按 cost 降序）
     *
     * @param array<string, array{key:string, calls:int, cost:float}> $acc 聚合结果
     * @param int                                                     $n   取前 N
     * @return array<int, array{key:string, calls:int, cost:float}>
     */
    protected function topN(array $acc, int $n): array
    {
        uasort($acc, static function (array $a, array $b): int {
            return $b['calls'] <=> $a['calls'] ?: ($b['cost'] <=> $a['cost']);
        });

        return array_values(array_slice($acc, 0, $n));
    }

    protected function assertCanView(): bool
    {
        $userId = $this->currentAdminId();

        if ($userId <= 0 || !class_exists(\wise\auth\facade\Rbac::class)) {
            return false;
        }

        try {
            if (\wise\auth\facade\Rbac::isSuperAdmin($userId, 'admin')) {
                return true;
            }
            return (bool) \wise\auth\facade\Rbac::check($userId, 'admin', self::PERMISSION_VIEW);
        } catch (\Throwable) {
            return false;
        }
    }

    protected function currentAdminId(): int
    {
        try {
            if (class_exists(\app\admin\facade\CurrentAdmin::class)) {
                return (int) \app\admin\facade\CurrentAdmin::getAdminId();
            }
        } catch (\Throwable) {
        }
        return 0;
    }
}
