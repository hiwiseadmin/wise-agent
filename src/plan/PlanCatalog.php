<?php
declare(strict_types=1);

namespace wise\agent\plan;

/**
 * 能力目录＼�
 *
 * 「可规划能力」的**裁剪后**视图，负责把它渲染成受预算约束的 Prompt 文本。
 *
 * ## 四级降级（预算从宽到紧，**能力名永远保住**）
 *
 * 1. `kind:target · 描述 · 参数 · 读写`（富余版）
 * 2. `kind:target · 参数 · 读写`（去掉描述）
 * 3. `kind:target · 读写`（去掉参数）
 * 4. 只有 `kind:target`（一行一个）
 * 5. 逗号连排 + 硬截断（极端预算下的兜底）
 *
 * 为什么不直接截断：被截断的能力名 = **LLM 根本不知道它存在**，
 * 而计划里的能力引用必须真实存在（`PlanValidator` 第 4 条）。
 * 因此宁可逐级丢描述与参数，也要先把「有哪些能力」讲完。
 */
final class PlanCatalog implements \JsonSerializable
{
    /** 目录渲染的默认预算（与 `plan.max_catalog_chars` 一致） */
    public const DEFAULT_MAX_CHARS = 4000;

    /**
     * 被黑名单隐藏的能力数（诚实标注：目录少了几项是有原因的）
     */
    private int $hidden = 0;

    /**
     * @param array<string, PlanCatalogEntry> $entries 键 = `kind:target`
     */
    public function __construct(
        private array $entries = [],
        int $hidden = 0,
    ) {
        $this->hidden = max(0, $hidden);
    }

    /**
     * @param PlanCatalogEntry[] $entries
     */
    public static function of(array $entries, int $hidden = 0): self
    {
        $map = [];

        foreach ($entries as $entry) {
            if ($entry instanceof PlanCatalogEntry) {
                $map[$entry->key()] = $entry;
            }
        }

        return new self($map, $hidden);
    }

    /**
     * @return array<string, PlanCatalogEntry>
     */
    public function all(): array
    {
        return $this->entries;
    }

    public function get(string $kind, string $target): ?PlanCatalogEntry
    {
        return $this->entries[$kind . ':' . $target] ?? null;
    }

    public function has(string $kind, string $target): bool
    {
        return isset($this->entries[$kind . ':' . $target]);
    }

    /**
     * @return array<string, PlanCatalogEntry>
     */
    public function byKind(string $kind): array
    {
        return array_filter($this->entries, static fn(PlanCatalogEntry $e): bool => $e->kind === $kind);
    }

    public function size(): int
    {
        return count($this->entries);
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    public function hiddenCount(): int
    {
        return $this->hidden;
    }

    /**
     * 目录文本＼�
     *
     * @param int $maxChars 预算（字符数，UTF-8）
     */
    public function toPrompt(int $maxChars = self::DEFAULT_MAX_CHARS): string
    {
        if ($this->isEmpty() || $maxChars <= 0) {
            return '';
        }

        $header = "## 可规划能力（只能引用下列 kind:target，不得编造）\n";

        $levels = [
            [$header, true, true],
            [$header, false, true],
            [$header, false, false],
            ["## 可规划能力\n", false, false],
        ];

        foreach ($levels as [$head, $withDescription, $withArgs]) {
            $lines = [$head];

            foreach ($this->entries as $entry) {
                $lines[] = $withDescription || $withArgs
                    ? $entry->toLine($withDescription, $withArgs)
                    : '- ' . $entry->key();
            }

            if ($this->hidden > 0) {
                $lines[] = sprintf('-（另有 %d 个能力被 plan.deny_capabilities 隐藏）', $this->hidden);
            }

            $text = rtrim(implode("\n", $lines));

            if (mb_strlen($text, 'UTF-8') <= $maxChars) {
                return $text;
            }
        }

        $names = implode('、', array_keys($this->entries));

        return mb_substr($names, 0, $maxChars, 'UTF-8');
    }

    /**
     * 完整目录（第 ② 层：按需拉取用）
     *
     * @return array<string, array<string, mixed>>
     */
    public function toArray(): array
    {
        $out = [];

        foreach ($this->entries as $key => $entry) {
            $out[$key] = $entry->toArray();
        }

        if ($this->hidden > 0) {
            $out['_hidden'] = ['count' => $this->hidden, 'reason' => 'plan.deny_capabilities'];
        }

        return $out;
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->toArray();
    }
}
