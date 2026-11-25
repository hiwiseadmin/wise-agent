<?php
declare(strict_types=1);

namespace wise\agent\http;

use think\Response;

/**
 * Server-Sent Events 响应
 *
 * 为什么必须走 Response 而不是 `header()` + `echo`：
 *   - FPM 下 `header()` 可直接生效，但在 think-swoole 常驻/协程运行时里，
 *     响应头与响应体必须经由 Response 写回协程上下文，直写 `header()` 不可靠；
 *   - 框架的 `Response::getContent()` / `sendData()` 会把内容强制转成字符串，
 *     流式场景无法预渲染，因此这里重写 `send()` 自行分片下发。
 *
 * 数据契约：`$data` 为可迭代的事件序列，每个元素形如
 *   `['type' => 'token', 'content' => '...', ...]`
 * 输出为 `event: {type}\ndata: {json}\n\n`，与既有前端解析逻辑保持一致。
 */
class SseResponse extends Response
{
    /** @var iterable<array<string, mixed>> */
    protected $data;

    /** 是否在发送前清理输出缓冲（单测中应关闭，避免关掉测试框架自身的缓冲） */
    protected bool $prepareOutput = true;

    /**
     * @param iterable<array<string, mixed>> $data          事件序列（生成器或数组）
     * @param int                            $code          HTTP 状态码
     * @param array<string, string>          $headers       额外响应头（如 CORS）
     * @param bool                           $prepareOutput 是否清理输出缓冲 / 解除超时限制
     */
    public function __construct(iterable $data, int $code = 200, array $headers = [], bool $prepareOutput = true)
    {
        $this->data          = $data;
        $this->code          = $code;
        $this->prepareOutput = $prepareOutput;
        $this->header = array_merge([
            'Content-Type'      => 'text/event-stream; charset=utf-8',
            'Cache-Control'     => 'no-cache',
            'Connection'        => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ], $headers);
    }

    /**
     * 流式响应没有可预渲染的内容
     */
    public function getContent(): string
    {
        return '';
    }

    /**
     * 发送响应头并逐条下发事件
     */
    public function send(): void
    {
        if ($this->prepareOutput) {
            $this->prepare();
        }

        if (!headers_sent()) {
            http_response_code($this->code);

            foreach ($this->header as $name => $value) {
                header($name . ': ' . $value);
            }
        }

        foreach ($this->data as $event) {
            $this->emit(is_array($event) ? $event : ['type' => 'message']);

            if ($this->prepareOutput) {
                $this->flush();
            }
        }
    }

    /**
     * 下送单条事件
     *
     * @param array<string, mixed> $event
     */
    protected function emit(array $event): void
    {
        $type = (string) ($event['type'] ?? 'message');
        $type = (string) preg_replace('/[^a-zA-Z0-9_-]/', '', $type);

        if ($type === '') {
            $type = 'message';
        }

        $payload = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        echo 'event: ' . $type . "\n";
        echo 'data: ' . $payload . "\n\n";
    }

    /**
     * 流式输出前的运行环境准备
     */
    protected function prepare(): void
    {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        @ini_set('output_buffering', 'off');

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
    }

    /**
     * 立即把已写入的输出推给客户端
     */
    protected function flush(): void
    {
        if (ob_get_level() > 0) {
            @ob_flush();
        }

        @flush();
    }
}
