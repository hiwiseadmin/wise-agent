<?php
declare(strict_types=1);

namespace wise\agent\client;

use wise\agent\exception\ProviderException;

/**
 * AI API 通信的 HTTP 客户端
 *
 * 基于 cURL 的轻量级 HTTP 客户端，支持重试和超时。
 * 使用指数退避进行重试。
 * 支持标准 POST 和流式 SSE（Server-Sent Events）端点。
 */
class HttpClient
{
    protected array $defaultOptions = [
        'timeout'    => 60,
        'max_retry'  => 2,
        'retry_delay_ms' => 500,
        'headers'    => [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ],
    ];

    protected array $options;

    public function __construct(array $options = [])
    {
        $this->options = array_replace_recursive($this->defaultOptions, $options);
    }

    /**
     * 发送 POST 请求
     *
     * @throws ProviderException
     */
    public function post(string $url, array $data, array $headers = [], array $options = []): array
    {
        return $this->request('POST', $url, $data, $headers, $options);
    }

    /**
     * 发送 GET 请求
     *
     * @throws ProviderException
     */
    public function get(string $url, array $headers = [], array $options = []): array
    {
        return $this->request('GET', $url, [], $headers, $options);
    }

    /**
     * 发送用于 SSE（Server-Sent Events）的流式 POST 请求
     *
     * 使用 CURLOPT_WRITEFUNCTION 实时处理每个接收到的数据块。
     * 解析 SSE data: 行并 yield 为事件。
     *
     * Yields:
     *   - ['type' => 'data', 'content' => '...'] 针对每个 SSE data: 行
     *   - ['type' => 'done'] 当流正常结束时
     *   - ['type' => 'error', 'message' => '...'] 发生错误时
     *
     * @param string $url     请求 URL
     * @param array  $body    请求体（将被 JSON 编码）
     * @param array  $headers 额外的 HTTP 头
     * @param array  $options cURL 选项
     * @return \Generator
     * @throws ProviderException
     */
    public function streamPost(string $url, array $body, array $headers = [], array $options = []): \Generator
    {
        $mergedOptions = array_replace_recursive($this->options, $options);
        $allHeaders = array_merge($mergedOptions['headers'] ?? [], $headers);
        $curlHeaders = [];
        foreach ($allHeaders as $key => $value) {
            $curlHeaders[] = "{$key}: {$value}";
        }

        $ch = curl_init();

        $curlOptions = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER         => false,
            CURLOPT_HTTPHEADER     => $curlHeaders,
            CURLOPT_TIMEOUT        => $mergedOptions['timeout'] ?? 300,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        ];

        // 代理支持
        if (!empty($mergedOptions['proxy'])) {
            $curlOptions[CURLOPT_PROXY] = $mergedOptions['proxy'];
        }

        // 用于部分 SSE 行的缓冲区
        $buffer = '';

        // CURLOPT_WRITEFUNCTION 回调 — 在每次收到数据块时被调用
        $curlOptions[CURLOPT_WRITEFUNCTION] = function ($ch, $data) use (&$buffer): int {
            $dataLen = strlen($data);
            if ($dataLen === 0) {
                return 0;
            }

            $buffer .= $data;

            // 从缓冲区处理完整的行
            while (($newlinePos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $newlinePos);
                $buffer = substr($buffer, $newlinePos + 1);

                // 去除尾部的 \r
                $line = rtrim($line, "\r");

                // SSE：空行 = 事件边界（此处无实际意义）
                if ($line === '') {
                    continue;
                }

                // SSE：以 ":" 开头的行是注释
                if (strpos($line, ':') === 0) {
                    continue;
                }

                // SSE："data:" 行承载有效载荷
                if (stripos($line, 'data:') === 0) {
                    $payload = trim(substr($line, 5));

                    // OpenAI 兼容 API 的 "[DONE]" 哨兵
                    if ($payload === '[DONE]') {
                        // 将由调用方处理 — 仅透传
                        continue;
                    }

                    // Yield 数据供调用方解析
                    // 我们使用一个技巧：写入通过引用传递的内部收集器数组
                    // 由于 Generator 不能在回调中直接使用，
                    // 我们将事件累积到队列中，主循环读取该队列
                }
            }

            // 我们将采用不同的方法 — 通过队列收集事件
            // WRITEFUNCTION 存储事件，我们在 curl_exec 之后 yield 它们
            return $dataLen;
        };

        curl_setopt_array($ch, $curlOptions);

        // 我们需要另一种方法：通过引用收集 SSE data 行
        $events = [];
        $error = null;

        // 重新设置 WRITEFUNCTION，使其能够访问 $events
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (&$buffer, &$events): int {
            $dataLen = strlen($data);
            if ($dataLen === 0) {
                return 0;
            }
            $buffer .= $data;
            while (($newlinePos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $newlinePos);
                $buffer = substr($buffer, $newlinePos + 1);
                $line = rtrim($line, "\r");
                if ($line === '' || strpos($line, ':') === 0) {
                    continue;
                }
                if (stripos($line, 'data:') === 0) {
                    $payload = trim(substr($line, 5));
                    if ($payload !== '') {
                        $events[] = ['type' => 'data', 'content' => $payload];
                    }
                }
            }
            return $dataLen;
        });

        curl_exec($ch);

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        curl_close($ch);

        // Yield 所有收集到的事件
        foreach ($events as $event) {
            yield $event;
        }

        // 处理 cURL 错误
        if ($curlErrno !== 0) {
            yield ['type' => 'error', 'message' => "cURL error: {$curlError} (code: {$curlErrno})"];
            return;
        }

        // 处理 HTTP 错误
        if ($httpCode >= 400) {
            // 尝试从收集到的 data 事件中提取错误消息
            $errorBody = '';
            foreach ($events as $event) {
                $errorBody .= $event['content'] ?? '';
            }
            $errorData = json_decode($errorBody, true);
            $errorMessage = $errorData['error']['message'] ?? $errorBody;
            if (empty($errorMessage)) {
                $errorMessage = "HTTP error {$httpCode}";
            }
            yield ['type' => 'error', 'message' => "API error [{$httpCode}]: {$errorMessage}"];
            return;
        }

        // 发送完成信号
        yield ['type' => 'done'];
    }

    /**
     * 核心请求方法，包含指数退避重试逻辑
     *
     * @throws ProviderException
     */
    protected function request(string $method, string $url, array $data = [], array $headers = [], array $options = []): array
    {
        $mergedOptions = array_replace_recursive($this->options, $options);
        $maxRetry = $mergedOptions['max_retry'] ?? 2;
        $retryDelay = $mergedOptions['retry_delay_ms'] ?? 500;

        $lastException = null;

        for ($attempt = 0; $attempt <= $maxRetry; $attempt++) {
            try {
                if ($attempt > 0) {
                    // 指数退避：delay * 2^(attempt-1)
                    $delay = $retryDelay * (2 ** ($attempt - 1));
                    usleep($delay * 1000);
                }
                return $this->doRequest($method, $url, $data, $headers, $mergedOptions);
            } catch (\Throwable $e) {
                $lastException = $e;
                // 仅在服务器错误（5xx）和网络错误时重试
                if ($e instanceof ProviderException) {
                    $statusCode = $e->getCode();
                    if ($statusCode > 0 && $statusCode < 500) {
                        throw $e;
                    }
                }
                // 非 ProviderException — 包装并重试
                // 如果已是最后一次重试，则重新包装并抛出
                if ($attempt >= $maxRetry) {
                    if ($e instanceof ProviderException) {
                        throw $e;
                    }
                    throw new ProviderException(
                        "Request failed after {$maxRetry} retries: {$e->getMessage()}",
                        0,
                        $e,
                        ['url' => $url]
                    );
                }
            }
        }

        throw $lastException ?? new ProviderException("Request failed after {$maxRetry} retries");
    }

    /**
     * 执行单次 HTTP 请求
     *
     * @throws ProviderException
     */
    protected function doRequest(string $method, string $url, array $data, array $headers, array $options): array
    {
        $ch = curl_init();

        $allHeaders = array_merge($options['headers'] ?? [], $headers);
        $curlHeaders = [];
        foreach ($allHeaders as $key => $value) {
            $curlHeaders[] = "{$key}: {$value}";
        }

        $curlOptions = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_HTTPHEADER     => $curlHeaders,
            CURLOPT_TIMEOUT        => $options['timeout'] ?? 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => true,
        ];

        if ($method === 'POST') {
            $curlOptions[CURLOPT_POST] = true;
            $curlOptions[CURLOPT_POSTFIELDS] = json_encode($data, JSON_UNESCAPED_UNICODE);
        }

        // 代理支持
        if (!empty($options['proxy'])) {
            $curlOptions[CURLOPT_PROXY] = $options['proxy'];
        }

        curl_setopt_array($ch, $curlOptions);

        $responseBody = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno !== 0) {
            throw new ProviderException(
                "cURL error: {$error} (code: {$errno})",
                $httpCode,
                null,
                ['url' => $url]
            );
        }

        if ($httpCode >= 400) {
            $errorData = json_decode($responseBody, true);
            $errorMessage = $errorData['error']['message'] ?? $responseBody;

            throw new ProviderException(
                "API error [{$httpCode}]: {$errorMessage}",
                $httpCode,
                null,
                [
                    'url'      => $url,
                    'response' => mb_substr($responseBody, 0, 500),
                ]
            );
        }

        $decoded = json_decode($responseBody, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        // 如果 JSON 解析失败但响应体非空，则包装它
        if (!empty(trim($responseBody))) {
            return ['content' => $responseBody];
        }

        return [];
    }
}
