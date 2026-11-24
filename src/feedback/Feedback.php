<?php
declare(strict_types=1);

namespace wise\agent\feedback;

/**
 * 用户反馈数据对象
 */
class Feedback
{
    public string $skillName;
    public int $rating;
    public ?string $comment = null;
    public ?string $correction = null;
    public array $context = [];
    public ?string $sessionId = null;
    public ?string $pipelineName = null;
    public int $userId = 0;
    public string $userType = 'admin';
    public string $createdAt;

    public function __construct(
        string $skillName,
        int $rating,
        ?string $comment = null,
        ?string $correction = null,
        array $context = []
    ) {
        $this->skillName  = $skillName;
        $this->rating     = max(1, min(5, $rating));
        $this->comment    = $comment;
        $this->correction = $correction;
        $this->context    = $context;
        $this->createdAt  = date('Y-m-d H:i:s');
    }

    public function toArray(): array
    {
        return [
            'skill_name'    => $this->skillName,
            'rating'        => $this->rating,
            'comment'       => $this->comment,
            'correction'    => $this->correction,
            'context'       => $this->context,
            'session_id'    => $this->sessionId,
            'pipeline_name' => $this->pipelineName,
            'user_id'       => $this->userId,
            'user_type'     => $this->userType,
            'create_time'   => $this->createdAt,
        ];
    }
}
