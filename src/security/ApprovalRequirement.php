<?php
declare(strict_types=1);

namespace wise\agent\security;

/**
 * 能力调用的审批要求
 *
 * 「权限（permission）」解决「能不能调用」，「审批（approval）」解决
 * 「这一次调用是否需要额外的人工介入」——两者解耦，权限一次性授予，
 * 审批按访问类型 / 风险等级 / 具体能力配置。
 *
 * @see \wise\agent\security\ApprovalPolicy
 */
final class ApprovalRequirement
{
    /** 直接执行，无需额外确认 */
    public const NONE = 'none';

    /** 需要人工确认后执行（同步阻断，等待批准） */
    public const CONFIRM = 'confirm';

    /** 需要提交审批流（异步，审批通过后再执行） */
    public const WORKFLOW = 'workflow';

    /**
     * @param string $mode   审批模式：none | confirm | workflow
     * @param string $rule   命中的规则（如 risk:high / access:write / tool:*），便于排障与审计
     * @param string $reason 人类可读说明
     */
    public function __construct(
        public readonly string $mode = self::NONE,
        public readonly string $rule = '',
        public readonly string $reason = ''
    ) {
    }

    public static function none(string $rule = '', string $reason = ''): self
    {
        return new self(self::NONE, $rule, $reason);
    }

    public static function confirm(string $rule, string $reason = ''): self
    {
        return new self(self::CONFIRM, $rule, $reason);
    }

    public static function workflow(string $rule, string $reason = ''): self
    {
        return new self(self::WORKFLOW, $rule, $reason);
    }

    /**
     * 是否需要人工介入
     */
    public function required(): bool
    {
        return $this->mode !== self::NONE;
    }

    /**
     * 模式严重度（数值越大越严格）
     */
    public static function severity(string $mode): int
    {
        return match ($mode) {
            self::WORKFLOW => 2,
            self::CONFIRM  => 1,
            default        => 0,
        };
    }

    /**
     * 取更严格的模式（用于把「能力自身下限」并入策略结论）
     */
    public static function strictest(string $a, string $b): string
    {
        return self::severity($a) >= self::severity($b) ? $a : $b;
    }

    public function toArray(): array
    {
        return [
            'mode'   => $this->mode,
            'rule'   => $this->rule,
            'reason' => $this->reason,
        ];
    }
}
