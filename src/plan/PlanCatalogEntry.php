<?php
declare(strict_types=1);

namespace wise\agent\plan;

/**
 * 能力目录条目＼�
 *
 * 「能规划什么」的最小单元：`kind:target` + 一句话描述 + 参数名清单 + 是否写类。
 *
 * **刻意不含完整 JSON Schema**：那是第 ② 层（按需拉取）的事。
 * 把完整 Schema 塞进目录是最容易发生的 Prompt 膨胀来源（现状事实：`AgentManager` 每步把
 * 全量工具 Schema 喂给 LLM，`src/agent/AgentManager.php:206`），B1 不重复这个错误。
 */
final class PlanCatalogEntry implements \JsonSerializable
{
    /**
     * @param string      $kind        见 `PlanStep::KIND_*`
     * @param string      $target      能力名（工具名 / skill 名 / 节点 fullType）
     * @param string      $description 一句话描述（可能为空）
     * @param string[]|null $args      参数名清单（`null` = Schema 不可得）
     * @param bool        $write       是否写类（由能力 access 推导）
     * @param string      $label       人类可读标签（节点有 label，工具没有）
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $target,
        public readonly string $description = '',
        public readonly ?array $args = null,
        public readonly bool $write = false,
        public readonly string $label = '',
    ) {
    }

    /**
     * 唯一键（`kind:target`）
     */
    public function key(): string
    {
        return $this->kind . ':' . $this->target;
    }

    /**
     * 目录行（第 ① 层）
     *
     * @param bool $withDescription 预算不足时降级为 false
     * @param bool $withArgs        预算更紧时降级为 false
     */
    public function toLine(bool $withDescription = true, bool $withArgs = true): string
    {
        $parts = [$this->key()];

        if ($withDescription) {
            $text = $this->label !== '' && $this->label !== $this->target
                ? $this->label . ($this->description !== '' ? '：' . $this->description : '')
                : $this->description;

            if ($text !== '') {
                $parts[] = $text;
            }
        }

        if ($withArgs) {
            $parts[] = $this->args === null
                ? '参数:未知'
                : ($this->args === [] ? '无参数' : '参数:' . implode('/', $this->args));
        }

        $parts[] = $this->write ? '【写】' : '只读';

        return '- ' . implode(' · ', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind'        => $this->kind,
            'target'      => $this->target,
            'label'       => $this->label,
            'description' => $this->description,
            'args'        => $this->args,
            'write'       => $this->write,
        ];
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->toArray();
    }
}
