<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use wise\agent\execution\ExecutionTracker;

/**
 * 回归：执行记录的**归属校验**与**不可猜测的追踪 ID**
 *
 * 背景（源码核验发现的两处缺口）：
 *  1. `GET /admin/ai/task/status` 只凭 `tracking_id` 就返回 `result`（任务产出）/`error`/`session_id`；
 *  2. `tracking_id` 原本是 `uniqid('track_', true)` —— 「前缀 + 微秒时间戳」，**低熵、可枚举**。
 * 两者叠加 ⇒ 任何登录用户都能顺着时间戳猜出他人的执行记录并读到产出。
 *
 * 本测试覆盖**可离线验证**的部分：ID 的形态/不可枚举性、归属判定的纯逻辑、
 * 以及「查不了就一律拒绝」的 fail-closed 行为（无 DB 环境下正是这条路径）。
 */
class ExecutionOwnershipTest extends TestCase
{

    public function testTrackingIdHasFixedUnpredictableShape(): void
    {
        $id = ExecutionTracker::generateTrackingId();

        $this->assertMatchesRegularExpression('/^track_[0-9a-f]{32}$/', $id, 'track_ + 128 位随机十六进制');
        $this->assertSame(38, strlen($id));
        $this->assertLessThanOrEqual(64, strlen($id), '不得超过 ai_agent_logs.tracking_id 的列宽（64）');
    }

    /**
     * 与旧实现的关键差异：旧格式是「时间戳 + `.` + 微秒」——
     * 同一秒内产生的一批 ID **前缀高度相同**，外部可按时间窗口枚举。
     */
    public function testTrackingIdIsNotTimestampLikeAndDoesNotCollide(): void
    {
        $ids = [];

        for ($i = 0; $i < 200; $i++) {
            $ids[] = ExecutionTracker::generateTrackingId();
        }

        $this->assertCount(200, array_unique($ids), '随机 ID 不应重复');

        foreach ($ids as $id) {
            $this->assertStringNotContainsString('.', $id, '旧 uniqid 格式会带小数点（微秒段），新格式没有');
        }

        $prefixes = array_unique(array_map(static fn(string $id): string => substr($id, 6, 4), $ids));

        $this->assertGreaterThanOrEqual(190, count($prefixes), 'ID 前缀必须高度分散（不可按时间枚举）');
    }

    /**
     * `start()` 必须改用 `generateTrackingId()`（防止有人改回 `uniqid`）
     *
     * 说明：这条断言读源码而不是跑 DB —— 归属与 ID 生成都在数据库调用之前发生，
     * 而本套件刻意不依赖 DB（CI 里没有 MySQL），所以用"契约钉住"的方式守住它。
     */
    public function testStartUsesTheRandomIdGenerator(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__) . '/src/execution/ExecutionTracker.php'
        );

        $this->assertStringContainsString('self::generateTrackingId()', $source);

        $code = (string) preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~'], '', $source);

        $this->assertStringNotContainsString("uniqid('track_'", $code, '不得再回到低熵的 uniqid 实现');
    }


    public function testRowOwnedByMatrix(): void
    {
        $row = ['user_id' => 7, 'user_type' => 'admin'];

        $this->assertTrue(ExecutionTracker::rowOwnedBy($row, 7, 'admin'), '同人同类型 ⇒ 属于');

        $this->assertFalse(ExecutionTracker::rowOwnedBy($row, 9, 'admin'), '换人 ⇒ 不属于');
        $this->assertFalse(ExecutionTracker::rowOwnedBy($row, 7, 'api'), '换类型 ⇒ 不属于');

        $this->assertTrue(
            ExecutionTracker::rowOwnedBy(['user_id' => '7', 'user_type' => 'admin'], 7, 'admin'),
            '字符串形态的 user_id 必须能正确比较'
        );

        $this->assertTrue(ExecutionTracker::rowOwnedBy([], 0, ''), '两边都为空身份 ⇒ 命中');
        $this->assertFalse(ExecutionTracker::rowOwnedBy([], 0, 'admin'), '类型缺失不得被当作 admin');
        $this->assertFalse(ExecutionTracker::rowOwnedBy([], 7, 'admin'));
        $this->assertFalse(
            ExecutionTracker::rowOwnedBy(['user_id' => 7], 7, 'admin'),
            '类型缺失 ⇒ 不认领（即使 user_id 相同）'
        );

        $this->assertTrue(ExecutionTracker::rowOwnedBy(['user_id' => null, 'user_type' => ''], 0, ''));
        $this->assertTrue(ExecutionTracker::rowOwnedBy(['user_id' => 'abc', 'user_type' => ''], 0, ''));
        $this->assertFalse(ExecutionTracker::rowOwnedBy(['user_id' => 'abc', 'user_type' => ''], 7, 'admin'));
    }


    public function testOwnershipAndStatusChecksFailClosedWithoutDatabase(): void
    {
        $this->assertFalse(ExecutionTracker::isOwnedBy('track_someone_else', 7, 'admin'));
        $this->assertNull(ExecutionTracker::getStatusForOwner('track_someone_else', 7, 'admin'));

        $this->assertFalse(ExecutionTracker::isOwnedBy('', 7, 'admin'));
        $this->assertNull(ExecutionTracker::getStatusForOwner('', 7, 'admin'));
    }
}
