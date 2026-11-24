<?php
declare(strict_types=1);

namespace wise\agent\pipeline\event;

use wise\agent\event\Event;

class PipelineStart extends Event
{
    public string $pipelineName;
    public array $input;
    public array $steps;

    public function __construct(string $pipelineName, array $input, array $steps)
    {
        parent::__construct();
        $this->pipelineName = $pipelineName;
        $this->input = $input;
        $this->steps = $steps;
    }
}
