<?php
declare(strict_types=1);

namespace app\service;

final class SafeRemoteUrl
{
    /** @return array{url:string,host:string,port:int,ip:string} */
    public function resolve(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $dnsHost = trim($host, '[]');
        if (!in_array($scheme, ['http', 'https'], true) || $dnsHost === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new \RuntimeException('采集地址必须是无用户信息的 HTTP(S) URL');
        }
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if ($port < 1 || $port > 65535) {
            throw new \RuntimeException('采集地址端口无效');
        }

        $ips = [];
        if (filter_var($dnsHost, FILTER_VALIDATE_IP)) {
            $ips[] = $dnsHost;
        } else {
            foreach (@dns_get_record($dnsHost, DNS_A | DNS_AAAA) ?: [] as $record) {
                $ip = (string) ($record['ip'] ?? $record['ipv6'] ?? '');
                if ($ip !== '') {
                    $ips[] = $ip;
                }
            }
        }
        foreach (array_unique($ips) as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                return ['url' => $url, 'host' => $dnsHost, 'port' => $port, 'ip' => $ip];
            }
        }
        throw new \RuntimeException('采集地址解析到内网、保留地址或无法解析');
    }
}
