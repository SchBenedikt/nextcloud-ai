<?php

declare(strict_types=1);

namespace OCA\EvaAi\Service;

use GuzzleHttp\Handler\CurlHandler;
use OCP\IConfig;
use Psr\Http\Message\RequestInterface;

/** Transfer-time policy for user-selected compatible providers and media URLs. */
class ProviderEndpointPolicy {
    public function __construct(private ?IConfig $config = null) {}

    public function handler(): callable {
        $transfer = new CurlHandler();
        return function (RequestInterface $request, array $options) use ($transfer) {
            // A proxy may resolve a different destination from the IP pinned here.
            // Reject configured proxies rather than silently weakening the boundary.
            if (!empty($options['proxy'])) {
                throw new ProviderException('Custom providers require direct egress; a configured proxy cannot enforce the provider address policy.');
            }
            $target = $this->validateTarget((string)$request->getUri());
            $options['allow_redirects'] = false;
            $options['proxy'] = ''; // Also disable implicit environment proxies.
            $options['curl'][CURLOPT_PROXY] = '';
            $options['curl'][CURLOPT_RESOLVE] = filter_var($target['host'], FILTER_VALIDATE_IP) !== false ? [] : [$target['host'] . ':' . $target['port'] . ':' . implode(',', array_map(
                static fn(string $ip): string => str_contains($ip, ':') ? '[' . $ip . ']' : $ip,
                $target['addresses']
            ))];
            return $transfer($request, $options);
        };
    }

    /** @return array{host:string,port:int,addresses:list<string>} */
    public function validateTarget(string $url): array {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'], $parts['scheme'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            throw new ProviderException('Provider URL must be an HTTP(S) URL without credentials or fragments.');
        }
        $host = strtolower(rtrim(trim($parts['host'], '[]'), '.'));
        if ($host === '' || str_contains($host, '%') || preg_match('/[^a-z0-9.:-]/', $host)) {
            throw new ProviderException('Provider hostname must be a canonical IP address or ASCII DNS name.');
        }
        $literal = filter_var($host, FILTER_VALIDATE_IP) !== false;
        if (!$literal && (str_contains($host, ':') || preg_match('/^(?:0x[0-9a-f]+|[0-9]+)(?:\.(?:0x[0-9a-f]+|[0-9]+))*$/i', $host))) {
            throw new ProviderException('Non-canonical numeric provider addresses are not allowed.');
        }
        $port = (int)($parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80));
        if ($port < 1 || $port > 65535) throw new ProviderException('Invalid provider port.');
        $origin = strtolower($parts['scheme']) . '://' . (str_contains($host, ':') ? '[' . $host . ']' : $host) . ':' . $port;
        $allowed = $this->privateOriginAllowed($origin);
        $addresses = $literal ? [$host] : $this->resolve($host);
        if ($addresses === [] || count($addresses) > 64) throw new ProviderException('Provider hostname could not be resolved safely.');
        foreach ($addresses as $address) {
            $ip = $this->normalizeIp($address);
            if ($ip === null || $this->forbidden($ip)) {
                throw new ProviderException('Provider destination is a reserved, link-local or metadata address.');
            }
            $public = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
            if (str_contains($ip, ':') && !$this->inRange($ip, '2000::/3')) $public = false;
            if (!$public && (!$allowed || !$this->privateAddress($ip))) {
                throw new ProviderException('Private provider destinations require an administrator-approved origin.');
            }
        }
        if (strtolower($parts['scheme']) !== 'https' && !$allowed) {
            throw new ProviderException('Provider connections require HTTPS unless the administrator approved this origin.');
        }
        return ['host' => $host, 'port' => $port, 'addresses' => array_values(array_unique($addresses))];
    }

    private function privateOriginAllowed(string $origin): bool {
        $raw = $this->config?->getAppValue(AppConfig::APP, 'provider_private_origins', '[]') ?? '[]';
        $origins = json_decode($raw, true);
        if (!is_array($origins) || count($origins) > 100) return false;
        // Explicit scheme/host/port strings; never read personal configuration.
        return in_array($origin, $origins, true);
    }

    /** DNS is resolved here, after Nextcloud middleware, and pinned for transfer. */
    protected function resolve(string $host, array $visited = []): array {
        if ($host === 'localhost') return ['127.0.0.1', '::1'];
        if (count($visited) >= 8 || isset($visited[$host])) return [];
        $visited[$host] = true;
        $records = @dns_get_record(rtrim($host, '.') . '.', DNS_A | DNS_AAAA | DNS_CNAME);
        if (!is_array($records) || count($records) > 64) return [];
        $addresses = [];
        $targets = [];
        foreach ($records as $record) {
            if (isset($record['ip'])) $addresses[] = $record['ip'];
            elseif (isset($record['ipv6'])) $addresses[] = $record['ipv6'];
            elseif (isset($record['target'])) {
                $targets[] = strtolower(rtrim($record['target'], '.'));
            }
            if (count($addresses) > 64) return [];
        }
        if ($addresses === [] && $targets !== []) {
            // Reject ambiguous aliases and bound total DNS work, not only depth.
            if (count(array_unique($targets)) !== 1) return [];
            return $this->resolve($targets[0], $visited);
        }
        return array_values(array_unique($addresses));
    }

    private function normalizeIp(string $ip): ?string {
        $bytes = @inet_pton($ip);
        if ($bytes === false) return null;
        if (strlen($bytes) === 16 && substr($bytes, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            return inet_ntop(substr($bytes, 12));
        }
        return inet_ntop($bytes);
    }

    private function inRange(string $ip, string $cidr): bool {
        [$network, $prefix] = explode('/', $cidr);
        $address = inet_pton($ip);
        $base = inet_pton($network);
        if ($address === false || $base === false || strlen($address) !== strlen($base)) return false;
        $whole = intdiv((int)$prefix, 8);
        $bits = (int)$prefix % 8;
        return substr($address, 0, $whole) === substr($base, 0, $whole)
            && ($bits === 0 || (ord($address[$whole]) & (255 << (8 - $bits))) === (ord($base[$whole]) & (255 << (8 - $bits))));
    }

    private function forbidden(string $ip): bool {
        if ($ip === '::1') return false; // Separately requires an approved origin.
        foreach (['0.0.0.0/8', '169.254.0.0/16', '100.64.0.0/10', '192.0.0.0/24', '192.0.2.0/24',
            '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '168.63.129.16/32', '224.0.0.0/4', '240.0.0.0/4',
            '::/128', '::/96', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64', '2001::/32', '2001:db8::/32',
            '2002::/16', 'fd00:ec2::254/128', 'fe80::/10', 'fec0::/10', 'ff00::/8'] as $range) {
            if ($this->inRange($ip, $range)) return true;
        }
        return false;
    }

    private function privateAddress(string $ip): bool {
        foreach (['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '127.0.0.0/8', '::1/128', 'fc00::/7'] as $range) {
            if ($this->inRange($ip, $range)) return true;
        }
        return false;
    }
}
