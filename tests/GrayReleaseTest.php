<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use wise\agent\security\GrayRelease;

/**
 * C3-2：GrayRelease 灰度放量判定（验收 13 的可离线部分）
 */
class GrayReleaseTest extends TestCase
{
    private const CAP = 'menu.action.host.user.delete';

    public function testDeterministicSameInputSameResult(): void
    {
        $gray = new GrayRelease([
            'enabled' => true,
            'rules'   => ['*' => ['percent' => 20]],
        ]);

        $actions = [];
        for ($i = 0; $i < 10; $i++) {
            $actions[] = $gray->decide(self::CAP, 'admin', 7, [])['action'];
        }

        $this->assertCount(1, array_unique($actions), '同 (user, capability, percent) 多次调用结果恒定');
    }

    public function testPercentZeroAlwaysSandbox(): void
    {
        $gray = new GrayRelease([
            'enabled' => true,
            'rules'   => ['*' => ['percent' => 0]],
        ]);

        $result = $gray->decide(self::CAP, 'admin', 7, []);

        $this->assertSame('sandbox', $result['action'], 'percent=0 一律不放量（降级为沙箱）');
        $this->assertSame('not_released', $result['reason']);
    }

    public function testPercentHundredAlwaysAllowed(): void
    {
        $gray = new GrayRelease([
            'enabled' => true,
            'rules'   => ['*' => ['percent' => 100]],
        ]);

        $result = $gray->decide(self::CAP, 'admin', 7, []);

        $this->assertSame('allowed', $result['action'], 'percent=100 一律放量');
        $this->assertSame('percent', $result['reason']);
    }

    public function testExemptRoleAlwaysAllowed(): void
    {
        $gray = new GrayRelease([
            'enabled'      => true,
            'exempt_roles' => ['super_admin'],
            'rules'        => ['*' => ['percent' => 0]],
        ]);

        $result = $gray->decide(self::CAP, 'admin', 7, ['super_admin']);

        $this->assertSame('allowed', $result['action'], 'exempt_roles 命中即放量');
        $this->assertSame('exempt_role', $result['reason']);
    }

    public function testExplicitUserAllowed(): void
    {
        $gray = new GrayRelease([
            'enabled' => true,
            'rules'   => ['*' => ['percent' => 0, 'users' => [7]]],
        ]);

        $result = $gray->decide(self::CAP, 'admin', 7, []);

        $this->assertSame('allowed', $result['action']);
        $this->assertSame('explicit_user', $result['reason']);
    }

    public function testExplicitRoleAllowed(): void
    {
        $gray = new GrayRelease([
            'enabled' => true,
            'rules'   => ['*' => ['percent' => 0, 'roles' => ['ops']]],
        ]);

        $result = $gray->decide(self::CAP, 'admin', 7, ['ops']);

        $this->assertSame('allowed', $result['action']);
        $this->assertSame('explicit_role', $result['reason']);
    }

    public function testExplicitDeny(): void
    {
        $gray = new GrayRelease([
            'enabled' => true,
            'rules'   => ['*' => ['percent' => 100, 'denied' => [self::CAP]]],
        ]);

        $result = $gray->decide(self::CAP, 'admin', 7, []);

        $this->assertSame('denied', $result['action'], '显式 denied 优先于 percent 放量');
        $this->assertSame('explicit_deny', $result['reason']);
    }

    public function testDisabledAllows(): void
    {
        $gray = new GrayRelease([
            'enabled' => false,
            'rules'   => ['*' => ['percent' => 0]],
        ]);

        $result = $gray->decide(self::CAP, 'admin', 7, []);

        $this->assertSame('allowed', $result['action'], 'enabled=false 灰度关闭，不拦截');
        $this->assertSame('gray_disabled', $result['reason']);
    }

    public function testLongestPrefixMatchWins(): void
    {
        $gray = new GrayRelease([
            'enabled' => true,
            'rules'   => [
                'menu.action.host.*' => ['percent' => 100],
                '*'                  => ['percent' => 0],
            ],
        ]);

        $result = $gray->decide(self::CAP, 'admin', 7, []);

        $this->assertSame('allowed', $result['action'], '更具体的前缀规则优先于 * 兜底');
        $this->assertSame('menu.action.host.*', $result['rule']);
    }

    public function testMonotonicRelease(): void
    {
        $capability = self::CAP;
        $userType   = 'admin';
        $roles      = [];

        $releasedAt20 = [];
        for ($uid = 1; $uid <= 1000; $uid++) {
            $r = (new GrayRelease(['enabled' => true, 'rules' => ['*' => ['percent' => 20]]]))
                ->decide($capability, $userType, $uid, $roles);
            if ($r['action'] === 'allowed') {
                $releasedAt20[] = $uid;
            }
        }

        $this->assertNotEmpty($releasedAt20, 'percent=20 时应有部分用户放量');

        $gray50 = new GrayRelease(['enabled' => true, 'rules' => ['*' => ['percent' => 50]]]);
        foreach ($releasedAt20 as $uid) {
            $r = $gray50->decide($capability, $userType, $uid, $roles);
            $this->assertSame('allowed', $r['action'], "user {$uid} 在 percent=50 仍放量（单调放量）");
        }
    }
}
