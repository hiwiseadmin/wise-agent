<?php
declare(strict_types=1);

namespace wise\agent\Tool\builtin;

use wise\agent\Tool\BaseTool;

/**
 * HTTP request tool
 *
 * Makes HTTP requests to external APIs. Disabled by default for security.
 * Includes SSRF protection via DNS resolution and IP blocking.
 */
class HttpRequestTool extends BaseTool
{
    /**
     * Blocked IP ranges (private, reserved, loopback, link-local)
     */
    protected const BLOCKED_IP_RANGES = [
        ['10.0.0.0',     '10.255.255.255'],    // 10.0.0.0/8
        ['172.16.0.0',   '172.31.255.255'],    // 172.16.0.0/12
        ['192.168.0.0',  '192.168.255.255'],   // 192.168.0.0/16
        ['169.254.0.0',  '169.254.255.255'],   // 169.254.0.0/16 (link-local)
        ['0.0.0.0',      '0.255.255.255'],     // 0.0.0.0/8
        ['127.0.0.0',    '127.255.255.255'],   // 127.0.0.0/8 (loopback)
        ['::1',          '::1'],               // IPv6 loopback
        ['fc00::',       'fdff:ffff:ffff:ffff:ffff:ffff:ffff:ffff'], // IPv6 unique local
        ['fe80::',       'febf:ffff:ffff:ffff:ffff:ffff:ffff:ffff'], // IPv6 link-local
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

        // Parse URL and validate host
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';

        if (empty($host)) {
            return json_encode(['error' => 'Invalid URL: missing host']);
        }

        // Block raw hostnames via filter_var for direct IP detection
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if ($this->isBlockedIp($host)) {
                return json_encode(['error' => 'Requests to internal IP addresses are not allowed']);
            }
        } else {
            // Resolve hostname via DNS to prevent rebinding attacks
            $ips = $this->resolveHost($host);
            if ($ips !== null) {
                // Check all resolved IPs
                foreach ($ips as $ip) {
                    if (filter_var($ip, FILTER_VALIDATE_IP) && $this->isBlockedIp($ip)) {
                        return json_encode(['error' => 'Requests to internal IP addresses are not allowed']);
                    }
                }
            }

            // Also check if host is a localhost variant
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

            // Add headers
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

            // Truncate response to 50KB
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
     * Check if an IP address falls within blocked ranges
     */
    protected function isBlockedIp(string $ip): bool
    {
        // Normalize IPv6 loopback
        if ($ip === '::1' || $ip === '0:0:0:0:0:0:0:1') {
            return true;
        }

        // Check IPv4 ranges using long representation
        $ipLong = ip2long($ip);
        if ($ipLong !== false) {
            foreach (self::BLOCKED_IP_RANGES as $range) {
                $start = ip2long($range[0]);
                $end = ip2long($range[1]);
                if ($start !== false && $end !== false) {
                    // Skip IPv6 ranges in ip2long comparison
                    if ($ipLong >= $start && $ipLong <= $end) {
                        return true;
                    }
                }
            }
        } else {
            // IPv6: check against blocked IPv6 ranges
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
     * Resolve a hostname to IP addresses for SSRF prevention
     *
     * @param string $host Hostname to resolve
     * @return string[]|null Array of IPs, or null if resolution failed
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

        // Fallback to gethostbyname for A records
        $ip = gethostbyname($host);
        if ($ip !== $host && filter_var($ip, FILTER_VALIDATE_IP)) {
            return [$ip];
        }

        return null;
    }
}
