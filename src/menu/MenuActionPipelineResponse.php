<?php
declare(strict_types=1);

namespace wise\agent\menu;

use think\Response;

/**
 * 中间件链的占位响应（A1-5 内部实现细节）
 *
 * `think\Response` 是抽象类，而 TP 自带的 `think\response\Json` / `Html` 构造函数都需要容器
 * 注入 `Cookie`（`think/response/Html.php:29`），无法在「无容器 / CLI」场景构造。
 *
 * 中间件链的每个 `handle(Request, Closure): Response` 都声明了返回类型，因此最内层执行体
 * 必须回一个 `Response`；同时 `AuditLog` / `OperationLog` 这类**在 `$next` 之后落盘**的中间件
 * 需要读取 `getCode()`（判定 result=success/failure）与 `getContent()`（取响应体）。
 *
 * 本类只提供这两件事：
 *   - 真实业务返回的是 `Response` 时，链上直接用真实响应（不经过本类）；
 *   - 返回数组 / 字符串 / 只读沙箱预演结果时，用本类包一层，使审计与操作日志能正常落盘。
 */
final class MenuActionPipelineResponse extends Response
{
    /** @var string */
    protected $contentType = 'text/html';

    public function __construct(mixed $data = '', int $code = 200)
    {
        $this->init($data, $code);
    }

    /**
     * {@inheritDoc}
     */
    public function output($data): string
    {
        return is_scalar($data) ? (string) $data : '';
    }
}
