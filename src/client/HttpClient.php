<?php
declare(strict_types=1);

namespace wise\agent\client;

use think\facade\Cache;
use wise\agent\exception\ProviderException;
use wise\agent\exception\ServiceUnavailableException;
use wise\support\circuit\CircuitBreaker;
use wise\support\runtime\Runtime;

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

    /** @var CircuitBreaker|null 断路器（可选，按 AI Provider 维度隔离） */
    protected ?CircuitBreaker $breaker = null;

    public function __construct(array $options = [])
    {
        $this->options = array_replace_recursive($this->defaultOptions, $options);

        if (!empty($this->options['breaker_service'])) {
            $this->breaker = $this->createBreaker();
        }
    }

    /**
     * 创建断路器（失败时降级为「无熔断」）
     *
     * 熔断是增强项，不是必需项。缓存驱动配置错误、容器未就绪（CLI / 单测）时，
     * 原实现会在构造期直接抛异常，导致整个 AI 调用链不可用 —— 这比"没有熔断"
     * 严重得多。因此这里整体降级。
     */
    protected function createBreaker(): ?CircuitBreaker
    {
        try {
            $breakerCfg = $this->options['breaker_config'] ?? [];

            return new CircuitBreaker(
                $this->options['breaker_service'],
                Cache::store((string) \think\facade\Config::get('cache.default', 'file')),
                [
                    'failure_threshold' => $breakerCfg['failure_threshold'] ?? 5,
                    'timeout'           => $breakerCfg['timeout'] ?? 60,
                    'key_prefix'        => $breakerCfg['key_prefix'] ?? 'wise_agent:cb',
                ]
            );
        } catch (\Throwable) {
            return null;
        }
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

        if ($this->breaker !== null && !$this->breaker->isAvailable()) {
            throw new ServiceUnavailableException(
                retryAfter: (int) ($this->options['breaker_config']['timeout'] ?? 60),
                serviceName: $this->options['breaker_service'] ?? ''
            );
        }

        $allHeaders = array_merge($mergedOptions['headers'] ?? [], $headers);
        $curlHeaders = [];
        foreach ($allHeaders as $key => $value) {
            $curlHeaders[] = "{$key}: {$value}";
        }

        $ch = curl_init();

        $sslVerify = (bool) ($mergedOptions['ssl_verify'] ?? true);

        $curlOptions = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER         => false,
            CURLOPT_HTTPHEADER     => $curlHeaders,
            CURLOPT_TIMEOUT        => $mergedOptions['timeout'] ?? 300,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => $sslVerify,
            CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $this->encodeJson($body),
        ];

        $caBundle = (string) ($mergedOptions['ca_bundle'] ?? '');
        if ($caBundle !== '' && is_file($caBundle)) {
            $curlOptions[CURLOPT_CAINFO] = $caBundle;
        }

        if (!empty($mergedOptions['proxy'])) {
            $curlOptions[CURLOPT_PROXY] = $mergedOptions['proxy'];
        }

        $events = [];
        $buffer = '';

        $curlOptions[CURLOPT_WRITEFUNCTION] = function ($ch, $data) use (&$buffer, &$events): int {
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
        };

        curl_setopt_array($ch, $curlOptions);

        $mh = curl_multi_init();
        curl_multi_add_handle($mh, $ch);
        $running = 0;
        do {
            $status = curl_multi_exec($mh, $running);
            while ($events !== []) {
                yield array_shift($events);
            }
            if ($running > 0 && $status === CURLM_OK) {
                curl_multi_select($mh, 0.2);
            }
        } while ($running > 0 && $status === CURLM_OK);

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        curl_multi_remove_handle($mh, $ch);
        curl_multi_close($mh);
        curl_close($ch);

        while ($events !== []) {
            yield array_shift($events);
        }

        if ($curlErrno !== 0) {
            $this->breaker?->reportFailure();
            yield ['type' => 'error', 'message' => "cURL error: {$curlError} (code: {$curlErrno})"];
            return;
        }

        if ($httpCode >= 400) {
            $errorBody = '';
            if (!empty(trim($buffer))) {
                $lines = explode("\n", $buffer);
                foreach ($lines as $line) {
                    $line = rtrim($line, "\r");
                    if ($line === '[DONE]' || $line === '') {
                        continue;
                    }
                    if (stripos($line, 'data:') === 0) {
                        $payload = trim(substr($line, 5));
                        if ($payload !== '' && $payload !== '[DONE]') {
                            $errorBody .= $payload;
                        }
                    }
                }
            }
            if (empty($errorBody)) {
                $errorBody = trim($buffer);
            }
            $errorData = json_decode($errorBody, true);
            $errorMessage = is_array($errorData) ? ($errorData['error']['message'] ?? $errorBody) : $errorBody;
            if (empty($errorMessage) || $errorMessage === '[DONE]') {
                $errorMessage = "HTTP error {$httpCode}";
            }
            yield ['type' => 'error', 'message' => "API error [{$httpCode}]: {$errorMessage}"];
            if ($httpCode >= 500) {
                $this->breaker?->reportFailure();
            }
            return;
        }

        $this->breaker?->reportSuccess();
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

        if ($method === 'POST') {
            $this->encodeJson($data);
        }

        $lastException = null;

        for ($attempt = 0; $attempt <= $maxRetry; $attempt++) {
            try {
                if ($this->breaker !== null && $attempt === 0 && !$this->breaker->isAvailable()) {
                    throw new ServiceUnavailableException(
                        retryAfter: (int) ($this->options['breaker_config']['timeout'] ?? 60),
                        serviceName: $this->options['breaker_service'] ?? ''
                    );
                }
                if ($attempt > 0) {
                    $delay = $retryDelay * (2 ** ($attempt - 1));
                    Runtime::sleep($delay / 1000);
                }
                $result = $this->doRequest($method, $url, $data, $headers, $mergedOptions);
                $this->breaker?->reportSuccess();
                return $result;
            } catch (\Throwable $e) {
                $lastException = $e;
                if ($e instanceof ProviderException) {
                    $statusCode = $e->getHttpCode();
                    if ($statusCode > 0 && $statusCode < 500) {
                        throw $e;
                    }
                }
                if ($attempt >= $maxRetry) {
                    $this->breaker?->reportFailure();
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
     * 编码请求体，失败即抛出可读异常
     *
     * 原实现直接把 `json_encode()` 的返回值塞进 `CURLOPT_POSTFIELDS`：
     * 遇到非法 UTF-8（典型来源：Windows 控制台 GBK 输入、GBK 数据库字段、
     * 本地文件按 GBK 读入的 Prompt）时 `json_encode()` 返回 `false`，
     * curl 收到 `false` 会发出**空 body**，上游返回
     * `400 Failed to parse the request body as JSON: EOF while parsing a value at line 1 column 0`，
     * 排查成本极高。这里把失败变成一条能定位的异常。
     *
     * @throws ProviderException
     */
    protected function encodeJson(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new ProviderException(
                'Request body is not JSON encodable: ' . json_last_error_msg()
                . '（常见原因：请求文本含非 UTF-8 字节，例如 Windows 控制台以 GBK 输入中文、'
                . '或 GBK 数据源读入的上下文）',
                0,
                null,
                ['json_error' => json_last_error()]
            );
        }

        return $json;
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

        $sslVerify = (bool) ($options['ssl_verify'] ?? true);

        $curlOptions = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_HTTPHEADER     => $curlHeaders,
            CURLOPT_TIMEOUT        => $options['timeout'] ?? 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => $sslVerify,
            CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
        ];

        if ($method === 'POST') {
            $curlOptions[CURLOPT_POST] = true;
            $curlOptions[CURLOPT_POSTFIELDS] = $this->encodeJson($data);
        }

        $caBundle = (string) ($options['ca_bundle'] ?? '');
        if ($caBundle !== '' && is_file($caBundle)) {
            $curlOptions[CURLOPT_CAINFO] = $caBundle;
        }

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

        if (!empty(trim($responseBody))) {
            return ['content' => $responseBody];
        }

        return [];
    }
}
