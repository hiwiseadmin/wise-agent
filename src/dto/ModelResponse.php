<?php
declare(strict_types=1);

namespace wise\agent\dto;

/**
 * 模型适配器统一响应 DTO
 *
 * AdapterProviderInterface::chat() 的标准返回值。
 * 与 AiClientInterface::chat() 的返回格式保持一致，额外增加 adapter_id。
 */
class ModelResponse
{
    protected string $content;
    protected array $toolCalls;
    protected array $usage;
    protected ?string $finishReason;
    protected ?string $adapterId;
    protected array $extra;

    public function __construct(
        string $content = '',
        array $toolCalls = [],
        array $usage = [],
        ?string $finishReason = null,
        ?string $adapterId = null,
        array $extra = []
    ) {
        $this->content      = $content;
        $this->toolCalls    = $toolCalls;
        $this->usage        = $usage;
        $this->finishReason = $finishReason;
        $this->adapterId    = $adapterId;
        $this->extra        = $extra;
    }

    public function getContent(): string { return $this->content; }
    public function setContent(string $content): void { $this->content = $content; }

    public function getToolCalls(): array { return $this->toolCalls; }
    public function setToolCalls(array $toolCalls): void { $this->toolCalls = $toolCalls; }

    public function getUsage(): array { return $this->usage; }
    public function setUsage(array $usage): void { $this->usage = $usage; }

    public function getFinishReason(): ?string { return $this->finishReason; }
    public function setFinishReason(?string $finishReason): void { $this->finishReason = $finishReason; }

    public function getAdapterId(): ?string { return $this->adapterId; }
    public function setAdapterId(?string $adapterId): void { $this->adapterId = $adapterId; }

    /**
     * 是否有工具调用
     */
    public function hasToolCalls(): bool
    {
        return !empty($this->toolCalls);
    }

    /**
     * 转换为数组
     */
    public function toArray(): array
    {
        return [
            'content'       => $this->content,
            'tool_calls'    => $this->toolCalls,
            'usage'         => $this->usage,
            'finish_reason' => $this->finishReason,
            'adapter_id'    => $this->adapterId,
            'extra'         => $this->extra,
        ];
    }

    /**
     * 从 AiClientInterface::chat() 的返回格式构建
     */
    public static function fromArray(array $data, ?string $adapterId = null): self
    {
        return new self(
            $data['content'] ?? '',
            $data['tool_calls'] ?? [],
            $data['usage'] ?? [],
            $data['finish_reason'] ?? null,
            $adapterId,
            array_diff_key($data, array_flip(['content', 'tool_calls', 'usage', 'finish_reason']))
        );
    }
}
