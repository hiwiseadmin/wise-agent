<?php
declare(strict_types=1);

namespace wise\agent\contract;

/**
 * 工具调用请求上下文
 *
 * 传递给 ToolProviderInterface::execute() 的上下文对象，
 * 包含当前用户/租户/请求信息，供提供者做细粒度鉴权。
 */
class RequestContext
{
    protected int $userId;
    protected string $userType;
    protected ?int $tenantId;
    protected string $requestId;
    protected int $timestamp;
    protected array $extra;

    public function __construct(
        int $userId = 0,
        string $userType = 'admin',
        ?int $tenantId = null,
        string $requestId = '',
        array $extra = []
    ) {
        $this->userId    = $userId;
        $this->userType  = $userType;
        $this->tenantId  = $tenantId;
        $this->requestId = $requestId ?: uniqid('req_', true);
        $this->timestamp = time();
        $this->extra     = $extra;
    }

    public function getUserId(): int { return $this->userId; }
    public function setUserId(int $userId): void { $this->userId = $userId; }

    public function getUserType(): string { return $this->userType; }
    public function setUserType(string $userType): void { $this->userType = $userType; }

    public function getTenantId(): ?int { return $this->tenantId; }
    public function setTenantId(?int $tenantId): void { $this->tenantId = $tenantId; }

    public function getRequestId(): string { return $this->requestId; }
    public function getTimestamp(): int { return $this->timestamp; }

    /**
     * 获取扩展字段
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->extra[$key] ?? $default;
    }

    /**
     * 设置扩展字段
     */
    public function set(string $key, mixed $value): void
    {
        $this->extra[$key] = $value;
    }

    /**
     * 转换为数组（用于日志/序列化）
     */
    public function toArray(): array
    {
        return [
            'user_id'    => $this->userId,
            'user_type'  => $this->userType,
            'tenant_id'  => $this->tenantId,
            'request_id' => $this->requestId,
            'timestamp'  => $this->timestamp,
            'extra'      => $this->extra,
        ];
    }

    /**
     * 从 ThinkPHP 请求中构建上下文
     */
    public static function fromRequest(\think\Request $request): self
    {
        $userId   = $request->adminId ?? $request->uid ?? 0;
        $userType = $request->adminId ? 'admin' : 'guest';

        return new self($userId, $userType, null, $request->requestId ?? '');
    }
}
