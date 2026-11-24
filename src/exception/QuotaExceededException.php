<?php
declare(strict_types=1);

namespace wise\agent\exception;

/**
 * AI 用量配额超限异常＼�
 *
 * 由 `QuotaGuard` 判定超额后，在 `AgentManager::run()/resume()/runStream()` 入口抛出。
 * 携带 `quota{scope,key,used,limit,reset_at}`，由异常渲染链输出同核契约
 * `{code:1, msg, data:{quota:{...}}}`（不泄漏任务内容）。
 */
class QuotaExceededException extends AiException
{
    protected int $statusCode = 429;

    /**
     * @param array{scope: string, key: string, used: int, limit: int, reset_at: string} $quota
     */
    public function __construct(string $message, array $quota = [])
    {
        parent::__construct($message, 0, null, ['quota' => $quota]);
    }

    /**
     * @return array{scope: string, key: string, used: int, limit: int, reset_at: string}
     */
    public function getQuota(): array
    {
        $data = $this->getData();
        return is_array($data['quota'] ?? null) ? $data['quota'] : [];
    }
}
