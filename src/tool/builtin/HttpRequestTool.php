<?php
declare(strict_types=1);

namespace wise\agent\tool\builtin;

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
        ['10.0.0.0',     '10.255.255.255'],    // 10.0.0.0/8
        ['172.16.0.0',   '172.31.255.255'],    // 172.16.0.0/12
        ['192.168.0.0',  '192.168.255.255'],   // 192.168.0.0/16
        ['169.254.0.0',  '169.254.255.255'],   // 169.254.0.0/16（链路本地）
        ['0.0.0.0',      '0.255.255.255'],     // 0.0.0.0/8
        ['127.0.0.0',    '127.255.255.255'],   // 127.0.0.0/8（回环）
        ['::1',          '::1'],               // IPv6 回环
        ['fc00::',       'fdff:ffff:ffff:ffff:ffff:ffff:ffff:ffff'], // IPv6 唯一本地
        ['fe80::',       'febf:ffff:ffff:ffff:ffff:ffff:ffff:ffff'], // IPv6 链路本地
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

        // 解析 URL 并验证主机
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';

        if (empty($host)) {
            return json_encode(['error' => 'Invalid URL: missing host']);
        }

        // 通过 filter_var 阻止直接 IP 检测的原始主机名
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if ($this->isBlockedIp($host)) {
                return json_encode(['error' => 'Requests to internal IP addresses are not allowed']);
            }
        } else {
            // 通过 DNS 解析主机名以防止重绑定攻击
            $ips = $this->resolveHost($host);
            if ($ips !== null) {
                // 检查所有解析出的 IP
                foreach ($ips as $ip) {
                    if (filter_var($ip, FILTER_VALIDATE_IP) && $this->isBlockedIp($ip)) {
                        return json_encode(['error' => 'Requests to internal IP addresses are not allowed']);
                    }
                }
            }

            // 同时检查主机是否为 localhost 变体
            $lowerHost = strtolower($host);
            if (in_array($lowerHost, ['localhost', '127.0.0.1', '0.0.0.0', '::1'], true)
                || str_ends_with($lowerHost, '.local')
                || str_ends_with($lowerHost, '.localhost')
            ) {
                return json_encode(['error' => 'Requests to localhost are not allowed']);
            }
        }

        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }

            // 添加请求头
            $curlHeaders = ['User-Agent: WiseAgent/1.0'];
            foreach ($headers as $key => $value) {
                $curlHeaders[] = "{$key}: {$value}";
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                return json_encode(['error' => "cURL error: {$error}"]);
            }

            // 将响应截断到 50KB
            if (strlen($response) > 51200) {
                $response = substr($response, 0, 51200) . '... (truncated)';
            }

            return json_encode([
                'success'    => true,
                'statusCode' => $httpCode,
                'body'       => $response,
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            return json_encode(['error' => "Request error: " . $e->getMessage()]);
        }
    }

    /**
     * 检查 IP 地址是否在被阻止的范围内
     */
    protected function isBlockedIp(string $ip): bool
    {
        // 标准化 IPv6 回环
        if ($ip === '::1' || $ip === '0:0:0:0:0:0:0:1') {
            return true;
        }

        // 使用长整型表示检查 IPv4 范围
        $ipLong = ip2long($ip);
        if ($ipLong !== false) {
            foreach (self::BLOCKED_IP_RANGES as $range) {
                $start = ip2long($range[0]);
                $end = ip2long($range[1]);
                if ($start !== false && $end !== false) {
                    // 在 ip2long 比较中跳过 IPv6 范围
                    if ($ipLong >= $start && $ipLong <= $end) {
                        return true;
                    }
                }
            }
        } else {
            // IPv6：检查被阻止的 IPv6 范围
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

        // 回退到 gethostbyname 获取 A 记录
        $ip = gethostbyname($host);
        if ($ip !== $host && filter_var($ip, FILTER_VALIDATE_IP)) {
            return [$ip];
        }

        return null;
    }
}
