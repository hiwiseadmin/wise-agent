<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use wise\agent\cost\QuotaGuard;

/**
 * C2-3：QuotaGuard 配额守门（验收 9 的 enforce / fail-closed / fail-open）
 */
class QuotaGuardTest extends TestCase
{
    private function context(array $overrides = []): array
    {
        return array_merge([
            'user_type' => 'admin',
            'user_id'   => 7,
            'roles'     => [],
            'plugin'    => '',
            'provider'  => 'openai',
            'model'     => 'gpt-4o-mini',
            'weight'    => 100,
        ], $overrides);
    }

    public function testEnforceFalseOnlyObserves(): void
    {
        $guard = new QuotaGuard(
            ['enabled' => true, 'enforce' => false, 'per_user_daily_tokens' => 200000],
            fn (string $key, int $limit, int $weight): int => 0
        );

        $result = $guard->check($this->context());

        $this->assertTrue($result['allowed'], 'enforce=false 只观测不拒绝');
        $this->assertSame('observed', $result['reason']);
        $this->assertArrayHasKey('quota', $result);
    }

    public function testEnforceTrueRejectsOnExceeded(): void
    {
        $guard = new QuotaGuard(
            ['enabled' => true, 'enforce' => true, 'per_user_daily_tokens' => 200000],
            fn (string $key, int $limit, int $weight): int => 0
        );

        $result = $guard->check($this->context());

        $this->assertFalse($result['allowed']);
        $this->assertSame('quota_exceeded', $result['reason']);
        $this->assertSame('user', $result['quota']['scope']);
        $this->assertSame('user:admin:7', $result['quota']['key']);
        $this->assertSame(200000, $result['quota']['limit']);
        $this->assertArrayHasKey('reset_at', $result['quota']);
    }

    public function testFailClosedOnStoreUnavailable(): void
    {
        $guard = new QuotaGuard(
            ['enabled' => true, 'enforce' => true, 'per_user_daily_tokens' => 200000, 'fail_mode' => 'closed'],
            function (string $key, int $limit, int $weight): int {
                throw new \RuntimeException('redis down');
            }
        );

        $result = $guard->check($this->context());

        $this->assertFalse($result['allowed'], '配额 fail-closed：存储不可用 ⇒ 拒绝');
        $this->assertSame('store_unavailable', $result['reason']);
    }

    public function testFailOpenOnStoreUnavailable(): void
    {
        $guard = new QuotaGuard(
            ['enabled' => true, 'enforce' => true, 'per_user_daily_tokens' => 200000, 'fail_mode' => 'open'],
            function (string $key, int $limit, int $weight): int {
                throw new \RuntimeException('redis down');
            }
        );

        $result = $guard->check($this->context());

        $this->assertTrue($result['allowed'], '限流类 fail-open：存储不可用 ⇒ 放行');
    }

    public function testDisabledQuotaAlwaysAllows(): void
    {
        $guard = new QuotaGuard(['enabled' => false, 'enforce' => true, 'per_user_daily_tokens' => 1]);

        $result = $guard->check($this->context());

        $this->assertTrue($result['allowed']);
    }

    public function testZeroLimitMeansUnlimited(): void
    {
        $guard = new QuotaGuard(['enabled' => true, 'enforce' => true]);

        $result = $guard->check($this->context());

        $this->assertTrue($result['allowed']);
    }

    public function testModelDimensionUsesCallWeight(): void
    {
        $seen = [];
        $guard = new QuotaGuard(
            ['enabled' => true, 'enforce' => true, 'per_model_daily_calls' => 100],
            function (string $key, int $limit, int $weight) use (&$seen): int {
                $seen[] = [$key, $weight];
                return 1;
            }
        );

        $guard->check($this->context(['weight' => 500]));

        $this->assertSame([['model:openai:gpt-4o-mini', 1]], $seen);
    }

    public function testUserDimensionUsesTokenWeight(): void
    {
        $seen = [];
        $guard = new QuotaGuard(
            ['enabled' => true, 'enforce' => true, 'per_user_daily_tokens' => 200000],
            function (string $key, int $limit, int $weight) use (&$seen): int {
                $seen[] = [$key, $weight];
                return 1;
            }
        );

        $guard->check($this->context(['weight' => 1234]));

        $this->assertSame([['user:admin:7', 1234]], $seen);
    }
}
