<?php
declare(strict_types=1);

namespace wise\agent\tool\builtin;


use wise\support\context\ContextualLogger;
use wise\agent\security\CapabilityAccess;
use wise\agent\tool\BaseTool;

/**
 * HTTP 请求工具
 *
 * 向外部 API 发起 HTTP 请求。出于安全考虑，默认禁用。
 * 包含通过 DNS 解析和 IP 阻止实现的 SSRF 防护。
 */
class HttpRequestTool extends BaseTool
{
    /**
     * 被阻止的 IP 范围（私有、保留、回环、链路本地）
     */
    protected const BLOCKED_IP_RANGES = [
        ['10.0.0.0',     '10.255.255.255'],
        ['172.16.0.0',   '172.31.255.255'],
        ['192.168.0.0',  '192.168.255.255'],
        ['169.254.0.0',  '169.254.255.255'],
        ['0.0.0.0',      '0.255.255.255'],
        ['127.0.0.0',    '127.255.255.255'],
        ['::1',          '::1'],
        ['fc00::',       'fdff:ffff:ffff:ffff:ffff:ffff:ffff:ffff'],
        ['fe80::',       'febf:ffff:ffff:ffff:ffff:ffff:ffff:ffff'],
    ];

    public function getName(): string
    {
        return 'http_request';
    }

    public function getDescription(): string
    {
        return 'Make an HTTP request to an external URL. Supports GET and POST methods. Use for fetching data from APIs or web pages.';
    }

    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'url' => [
                    'type'        => 'string',
                    'description' => 'The URL to request',
                ],
                'method' => [
                    'type'        => 'string',
                    'enum'        => ['GET', 'POST'],
                    'description' => 'HTTP method (default: GET)',
                ],
                'headers' => [
                    'type'        => 'object',
                    'description' => 'HTTP headers as key-value pairs',
                ],
                'body' => [
                    'type'        => 'string',
                    'description' => 'Request body for POST requests',
                ],
            ],
            'required'   => ['url'],
        ];
    }

    public function getPermission(): string
    {
        return 'ai.tool.http_request';
    }

    public function requireConfirmation(): bool
    {
        return true;
    }

    public function getAccess(): string
    {
        return CapabilityAccess::WRITE;
    }

    public function getRiskLevel(): string
    {
        return CapabilityAccess::RISK_HIGH;
    }

    public function execute(array $arguments): string
    {
        $url = $arguments['url'] ?? '';
        $method = strtoupper($arguments['method'] ?? 'GET');
        $headers = $arguments['headers'] ?? [];
        $body = $arguments['body'] ?? '';

        if (empty($url)) {
            return json_encode(['error' => 'URL is required']);
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return json_encode(['error' => 'Invalid URL']);
        }

        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';

        if (empty($host)) {
            return json_encode(['error' => 'Invalid URL: missing host']);
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if ($this->isBlockedIp($host)) {
                return json_encode(['error' => 'Requests to internal IP addresses are not allowed']);
            }
        } else {
            $ips = $this->resolveHost($host);
            if ($ips === null) {
                return json_encode(['error' => 'Unable to resolve host for SSRF validation']);
            }
            foreach ($ips as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP) && $this->isBlockedIp($ip)) {
                    return json_encode(['error' => 'Requests to internal IP addresses are not allowed']);
                }
            }

            $lowerHost = strtolower($host);
            if (in_array($lowerHost, ['localhost', '127.0.0.1', '0.0.0.0', '::1'], true)
                || str_ends_with($lowerHost, '.local')
                || str_ends_with($lowerHost, '.localhost')
            ) {
                return json_encode(['error' => 'Requests to localhost are not allowed']);
            }
        }

        try {
            $maxRedirects = 3;
            $currentUrl = $url;
            $response = '';
            $httpCode = 0;
            $error = '';
            $redirect = 0;

            for ($redirect = 0; $redirect <= $maxRedirects; $redirect++) {
                $redirectParsed = parse_url($currentUrl);
                $redirectHost = $redirectParsed['host'] ?? '';
                if (empty($redirectHost)) {
                    return json_encode(['error' => 'Invalid redirect target: missing host']);
                }
                if (filter_var($redirectHost, FILTER_VALIDATE_IP)) {
                    if ($this->isBlockedIp($redirectHost)) {
                        return json_encode(['error' => 'Redirect target IP is blocked: ' . $redirectHost]);
                    }
                } else {
                    $redirectIps = $this->resolveHost($redirectHost);
                    if ($redirectIps !== null) {
                        foreach ($redirectIps as $ip) {
                            if (filter_var($ip, FILTER_VALIDATE_IP) && $this->isBlockedIp($ip)) {
                                return json_encode(['error' => 'Redirect target resolves to blocked IP: ' . $ip]);
                            }
                        }
                    }
                }

                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL            => $currentUrl,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 30,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_HEADER         => true,
                ]);

                if ($method === 'POST' && $redirect === 0) {
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                } else {
                    curl_setopt($ch, CURLOPT_HTTPGET, true);
                }

                $curlHeaders = ['User-Agent: WiseAgent/1.0'];
                foreach ($headers as $key => $value) {
                    $curlHeaders[] = "{$key}: {$value}";
                }
                curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);

                $fullResponse = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                $error = curl_error($ch);
                curl_close($ch);

                if ($fullResponse === false) {
                    return json_encode(['error' => "cURL error: {$error}"]);
                }

                if ($httpCode >= 300 && $httpCode < 400) {
                    $responseHeaders = substr($fullResponse, 0, $headerSize);
                    if (preg_match('/^Location:\s*(.+)$/im', $responseHeaders, $m)) {
                        $currentUrl = trim($m[1]);
                        if (!parse_url($currentUrl, PHP_URL_HOST)) {
                            $currentUrl = $redirectParsed['scheme'] . '://' . $redirectParsed['host']
                                . ($redirectParsed['port'] ? ':' . $redirectParsed['port'] : '')
                                . '/' . ltrim($currentUrl, '/');
                        }
                        continue;
                    }
                }

                $response = substr($fullResponse, $headerSize);
                break;
            }

            if ($redirect > $maxRedirects) {
                return json_encode(['error' => 'Too many redirects']);
            }

            if ($response === false) {
                return json_encode(['error' => "cURL error: {$error}"]);
            }

            if (strlen($response) > 51200) {
                $response = substr($response, 0, 51200) . '... (truncated)';
            }

            return json_encode([
                'success'    => true,
                'statusCode' => $httpCode,
                'body'       => $response,
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            ContextualLogger::error("[WiseAgent] HTTP request error: " . $e->getMessage(), [
                'url'       => substr($url, 0, 200),
                'method'    => $method,
                'exception' => get_class($e),
            ]);
            return json_encode(['error' => 'HTTP request failed. The server could not complete the request.']);
        }
    }

    /**
     * 检查 IP 地址是否在被阻止的范围内
     */
    protected function isBlockedIp(string $ip): bool
    {
        if ($ip === '::1' || $ip === '0:0:0:0:0:0:0:1') {
            return true;
        }

        $ipLong = ip2long($ip);
        if ($ipLong !== false) {
            foreach (self::BLOCKED_IP_RANGES as $range) {
                $start = ip2long($range[0]);
                $end = ip2long($range[1]);
                if ($start !== false && $end !== false) {
                    if ($ipLong >= $start && $ipLong <= $end) {
                        return true;
                    }
                }
            }
        } else {
            $ipBin = inet_pton($ip);
            if ($ipBin !== false) {
                foreach (self::BLOCKED_IP_RANGES as $range) {
                    $startBin = @inet_pton($range[0]);
                    $endBin = @inet_pton($range[1]);
                    if ($startBin !== false && $endBin !== false) {
                        if ($ipBin >= $startBin && $ipBin <= $endBin) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * 解析主机名为 IP 地址以进行 SSRF 防护
     *
     * @param string $host 要解析的主机名
     * @return string[]|null IP 地址数组，解析失败则返回 null
     */
    protected function resolveHost(string $host): ?array
    {
        $dnsRecords = @dns_get_record($host, DNS_A | DNS_AAAA);
        if ($dnsRecords !== false && !empty($dnsRecords)) {
            $ips = [];
            foreach ($dnsRecords as $record) {
                if (isset($record['ip'])) {
                    $ips[] = $record['ip'];
                } elseif (isset($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
            return !empty($ips) ? $ips : null;
        }

        $ip = gethostbyname($host);
        if ($ip !== $host && filter_var($ip, FILTER_VALIDATE_IP)) {
            return [$ip];
        }

        return null;
    }
}
