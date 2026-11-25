<?php
declare(strict_types=1);

namespace wise\agent\schedule;

/**
 * AI 定时任务定义
 */
class AiTask
{
    public string $id;
    public string $name;
    public string $type;
    public string $target;
    public array $input = [];
    public string $schedule;
    public ?string $source = null;
    public bool $enabled = true;
    public ?string $lastRun = null;
    public ?string $nextRun = null;
    public int $runCount = 0;

    public function __construct(
        string $id,
        string $name,
        string $type,
        string $target,
        string $schedule,
        array $input = [],
        ?string $source = null
    ) {
        $this->id       = $id;
        $this->name     = $name;
        $this->type     = $type;
        $this->target   = $target;
        $this->schedule = $schedule;
        $this->input    = $input;
        $this->source   = $source;
    }

    public function getScheduleType(): string
    {
        if (str_starts_with($this->schedule, 'event:')) {
            return 'event';
        }
        if (str_starts_with($this->schedule, 'interval:')) {
            return 'interval';
        }
        if (str_starts_with($this->schedule, 'once:')) {
            return 'once';
        }
        return 'cron';
    }

    public function getIntervalSeconds(): int
    {
        if ($this->getScheduleType() === 'interval') {
            return (int) str_replace('interval:', '', $this->schedule);
        }
        return 0;
    }

    public function toArray(): array
    {
        return [
            'id'       => $this->id,
            'name'     => $this->name,
            'type'     => $this->type,
            'target'   => $this->target,
            'input'    => $this->input,
            'schedule' => $this->schedule,
            'source'   => $this->source,
            'enabled'  => $this->enabled,
            'last_run' => $this->lastRun,
            'next_run' => $this->nextRun,
            'run_count' => $this->runCount,
        ];
    }
}
