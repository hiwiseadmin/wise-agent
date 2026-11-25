<?php
declare(strict_types=1);

namespace wise\agent\pipeline;

/**
 * 流水线执行结果
 */
class PipelineResult
{
    protected string $pipelineName;
    protected string $status;
    protected array $stepResults;
    protected ?string $error;
    protected float $duration;
    protected int $totalTokens;
    protected array $finalOutput;

    public function __construct(
        string $pipelineName,
        string $status = 'running',
        array $stepResults = [],
        ?string $error = null,
        float $duration = 0.0,
        int $totalTokens = 0,
        array $finalOutput = []
    ) {
        $this->pipelineName = $pipelineName;
        $this->status       = $status;
        $this->stepResults  = $stepResults;
        $this->error        = $error;
        $this->duration     = $duration;
        $this->totalTokens  = $totalTokens;
        $this->finalOutput  = $finalOutput;
    }

    public function getPipelineName(): string { return $this->pipelineName; }
    public function getStatus(): string { return $this->status; }
    public function getStepResults(): array { return $this->stepResults; }
    public function getError(): ?string { return $this->error; }
    public function getDuration(): float { return $this->duration; }
    public function getTotalTokens(): int { return $this->totalTokens; }
    public function getFinalOutput(): array { return $this->finalOutput; }

    public function isCompleted(): bool { return $this->status === 'completed'; }
    public function isFailed(): bool { return $this->status === 'failed'; }

    public function toArray(): array
    {
        return [
            'pipeline'      => $this->pipelineName,
            'status'        => $this->status,
            'step_results'  => $this->stepResults,
            'error'         => $this->error,
            'duration'      => round($this->duration, 3),
            'total_tokens'  => $this->totalTokens,
            'final_output'  => $this->finalOutput,
        ];
    }


    public function withStepResult(string $skillName, int $step, string $status, string $output): self
    {
        $this->stepResults[] = [
            'step'     => $step,
            'skill'    => $skillName,
            'output'   => mb_substr($output, 0, 5000),
            'status'   => $status,
            'duration' => 0,
        ];
        return $this;
    }

    public function withStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function withError(string $error): self
    {
        $this->error = $error;
        $this->status = 'failed';
        return $this;
    }

    public function withDuration(float $duration): self
    {
        $this->duration = $duration;
        return $this;
    }

    public function withTokens(int $tokens): self
    {
        $this->totalTokens += $tokens;
        return $this;
    }

    public function withFinalOutput(array $output): self
    {
        $this->finalOutput = $output;
        return $this;
    }
}
