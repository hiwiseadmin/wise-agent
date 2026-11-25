<?php
declare(strict_types=1);

namespace wise\agent\closure\connector;

use wise\agent\closure\ClosureConnectorInterface;
use wise\agent\closure\ClosureOptions;
use wise\agent\closure\PayloadMasker;

/**
 * 连接器基类＼�
 *
 * 三个内置连接器共用的事，只在这里实现一次：**下游缺失判定**、**脱敏**、**JSON 编码与截断**。
 *
 * ## 「下游包未安装」必须是 `skipped`，不是 `failed`
 *
 * 没装 `wise-notification` / `wise-webhook` / `wise-operation-log` **不是故障**，
 * 而是实例的能力边界（与 `ApprovalManager::notify()` 的既有守卫思路一致：
 * `class_exists` 守卫 + 记日志，不影响主流程）。
 * 因此基类提供 {@see self::missingDownstream()}：返回原因字符串 ⇒ 调用方返回 `skipped` 并**把原因写清楚**。
 *
 * ## 为什么把服务下沉到基类
 *
 * B2 要交付 3 个连接器，每个都需要「按 `closure.mask_fields` 再脱敏一遍」与「长度截断」；
 * 抄三份必然漂移（尤其是脱敏 —— 漂移的后果是**泄漏**）。
 */
abstract class AbstractConnector implements ClosureConnectorInterface
{
    use ClosureOptions;

    private ?PayloadMasker $masker;

    /**
     * @param array<string, mixed> $options `closure.*` 覆盖项
     */
    public function __construct(array $options = [], ?PayloadMasker $masker = null)
    {
        $this->setClosureOptions($options);
        $this->masker = $masker;
    }

    /**
     * 本连接器依赖的下游类
     *
     * @return array<string, string> `类名 => 人类可读说明`
     */
    abstract protected function requiredClasses(): array;

    /**
     * 下游是否缺失（`null` = 齐全；字符串 = 跳过原因）
     */
    public function missingDownstream(): ?string
    {
        foreach ($this->requiredClasses() as $class => $label) {
            if (!class_exists($class) && !interface_exists($class)) {
                return sprintf('%s 不可用（缺少 %s）', $label, $class);
            }
        }

        return null;
    }

    /**
     * 出站前的**第二道**脱敏（第一道在 `ClosureEventFactory` 入库前）
     *
     * 双保险的理由：连接器可能拿到 `payload` 之外的敏感字段（webhook 密钥、SMTP 配置…）。
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    protected function masked(array $payload): array
    {
        $masked = $this->masker()->mask($payload);

        return is_array($masked) ? $masked : [];
    }

    protected function masker(): PayloadMasker
    {
        return $this->masker ??= PayloadMasker::fromOptions($this->closureOptions());
    }

    /**
     * JSON 编码（失败不抛：编码失败本身不该打断投递，降级为 `null` 字符）
     */
    protected function json(mixed $value, int $maxChars = 0): string
    {
        if (is_string($value)) {
            $text = $value;
        } else {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);
            $text    = $encoded === false ? '' : $encoded;
        }

        return $maxChars > 0 ? $this->truncate($text, $maxChars) : $text;
    }

    protected function truncate(string $text, int $maxChars): string
    {
        if ($maxChars <= 0 || mb_strlen($text, 'UTF-8') <= $maxChars) {
            return $text;
        }

        return mb_substr($text, 0, $maxChars, 'UTF-8') . '…(truncated)';
    }

    protected function maxChars(string $key, int $default): int
    {
        return max(64, (int) $this->closureOpt($key, $default));
    }
}
