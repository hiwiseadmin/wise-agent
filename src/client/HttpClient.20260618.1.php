<?php
declare(strict_types=1);

namespace wise\agent\Client;

use wise\agent\Exception\ProviderException;

/**
 * HTTP client for AI API communication
 *
 * Lightweight HTTP client using cURL with retry and timeout support.
 * Uses exponential backoff for retries.
 * Supports both standard POST and streaming SSE (Server-Sent Events) endpoints.
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
     * Send a POST request
     *
     * @throws ProviderException
     */
    public function post(string $url, array $data, array $headers = [], array $options = []): array
    {
        return $this->request('POST', $url, $data, $headers, $options);
    }

    /**
     * Send a GET request
     *
     * @throws ProviderException
     */
    public function get(string $url, array $headers = [], array $options = []): array
    {
        return $this->request('GET', $url, [], $headers, $options);
    }

    /**
     * Send a streaming POST request for SSE (Server-Sent Events)
     *
     * Uses CURLOPT_WRITEFUNCTION to process each received chunk in real time.
     * Parses SSE data: lines and yields them as events.
     *
     * Yields:
     *   - ['type' => 'data', 'content' => '...'] for each SSE data: line
     *   - ['type' => 'done'] when the stream ends normally
     *   - ['type' => 'error', 'message' => '...'] on error
     *
     * @param string $url     Request URL
     * @param array  $body    Request body (will be JSON-encoded)
     * @param array  $headers Extra HTTP headers
     * @param array  $options cURL options
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

        // Proxy support
        if (!empty($mergedOptions['proxy'])) {
            $curlOptions[CURLOPT_PROXY] = $mergedOptions['proxy'];
        }

        // Buffer for partial SSE lines
        $buffer = '';

        // CURLOPT_WRITEFUNCTION callback — called for each chunk of data received
        $curlOptions[CURLOPT_WRITEFUNCTION] = function ($ch, $data) use (&$buffer): int {
            $dataLen = strlen($data);
            if ($dataLen === 0) {
                return 0;
            }

            $buffer .= $data;

            // Process complete lines from the buffer
            while (($newlinePos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $newlinePos);
                $buffer = substr($buffer, $newlinePos + 1);

                // Trim trailing \r
                $line = rtrim($line, "\r");

                // SSE: empty line = event boundary (not meaningful for us here)
                if ($line === '') {
                    continue;
                }

                // SSE: lines starting with ":" are comments
                if (strpos($line, ':') === 0) {
                    continue;
                }

                // SSE: "data:" lines carry the payload
                if (stripos($line, 'data:') === 0) {
                    $payload = trim(substr($line, 5));

                    // "[DONE]" sentinel from OpenAI-compatible APIs
                    if ($payload === '[DONE]') {
                        // Will be handled by the caller — just pass it through
                        continue;
                    }

                    // Yield the data for the caller to parse
                    // We use a trick: write to an internal collector array passed by reference
                    // Since Generator can't be used directly inside the callback,
                    // we accumulate events in a queue that the main loop reads
                }
            }

            // We'll use a different approach — collect events through a queue
            // The WRITEFUNCTION stores events, and we yield them after curl_exec
            return $dataLen;
        };

        curl_setopt_array($ch, $curlOptions);

        // We need a different approach: collect SSE data lines via reference
        $events = [];
        $error = null;

        // Re-set the WRITEFUNCTION with access to $events
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

        // Yield all collected events
        foreach ($events as $event) {
            yield $event;
        }

        // Handle cURL errors
        if ($curlErrno !== 0) {
            yield ['type' => 'error', 'message' => "cURL error: {$curlError} (code: {$curlErrno})"];
            return;
        }

        // Handle HTTP errors
        if ($httpCode >= 400) {
            // Try to extract error message from collected data events
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

        // Signal done
        yield ['type' => 'done'];
    }

    /**
     * Core request method with exponential backoff retry logic
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
                    // Exponential backoff: delay * 2^(attempt-1)
                    $delay = $retryDelay * (2 ** ($attempt - 1));
                    usleep($delay * 1000);
                }
                return $this->doRequest($method, $url, $data, $headers, $mergedOptions);
            } catch (\Throwable $e) {
                $lastException = $e;
                // Only retry on server errors (5xx) and network errors
                if ($e instanceof ProviderException) {
                    $statusCode = $e->getCode();
                    if ($statusCode > 0 && $statusCode < 500) {
                        throw $e;
                    }
                }
                // Non-ProviderException — wrap and retry
                // If it's the last retry, re-wrap and throw
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
     * Execute a single HTTP request
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

        // Proxy support
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

        // If JSON parsing fails but the body is non-empty, wrap it
        if (!empty(trim($responseBody))) {
            return ['content' => $responseBody];
        }

        return [];
    }
}
