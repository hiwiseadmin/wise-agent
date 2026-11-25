<?php
declare(strict_types=1);

namespace wise\agent\pipeline\event;

use wise\agent\event\Event;

class PipelineStepComplete extends Event
{
    public string $pipelineName;
    public int $stepIndex;
    public string $skillName;
    public string $output;
    public string $status;

    public function __construct(string $pipelineName, int $stepIndex, string $skillName, string $output, string $status)
    {
        parent::__construct();
        $this->pipelineName = $pipelineName;
        $this->stepIndex    = $stepIndex;
        $this->skillName    = $skillName;
        $this->output       = $output;
        $this->status       = $status;
    }
}

class PipelineComplete extends Event
{
    public string $pipelineName;
    public array $result;

    public function __construct(string $pipelineName, array $result)
    {
        parent::__construct();
        $this->pipelineName = $pipelineName;
        $this->result       = $result;
    }
}

class PipelineError extends Event
{
    public string $pipelineName;
    public int $stepIndex;
    public string $skillName;
    public string $error;

    public function __construct(string $pipelineName, int $stepIndex, string $skillName, string $error)
    {
        parent::__construct();
        $this->pipelineName = $pipelineName;
        $this->stepIndex    = $stepIndex;
        $this->skillName    = $skillName;
        $this->error        = $error;
    }
}
