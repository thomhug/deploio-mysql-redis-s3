<?php
namespace App;

use Predis\Client as Predis;

class Cache
{
    private ?Predis $redis = null;
    private ?string $lastStatus = null;

    private ?bool $lastHit = null;
    private ?string $lastKey = null;

    public function __construct()
    {
        $url = Util::env('REDIS_URL');
        if (!$url) return;

        try {
            $params = $url;

            // Wenn CA-PEM vorhanden: URL in Array-Parameter umwandeln und TLS-Optionen setzen
            $caPem = Util::env('REDIS_CA_PEM');
            if ($caPem) {
                $u = parse_url($url);
                if ($u === false || !isset($u['scheme'], $u['host'])) {
                    throw new \InvalidArgumentException('Ungültige REDIS_URL');
                }

                $isTls = in_array(strtolower($u['scheme']), ['rediss','tls'], true);
                $host  = $u['host'];
                $port  = $u['port'] ?? 6379;
                $pass  = $u['pass'] ?? null;
                $db    = isset($u['path']) ? (int) ltrim($u['path'], '/') : 0;

                $caPath = '/tmp/redis-ca.pem';
                file_put_contents($caPath, $caPem);

                $params = [
                    'scheme'   => $isTls ? 'tls' : 'tcp',
                    'host'     => $host,
                    'port'     => (int)$port,
                    'password' => $pass,
                    'database' => $db,
                ];

                if ($isTls) {
                    // Strenges TLS: CA verifizieren + Hostname prüfen
                    $params['ssl'] = [
                        'cafile'           => $caPath,
                        'verify_peer'      => true,
                        'verify_peer_name' => true,
                        'peer_name'        => $host, // SNI/Hostname
                    ];
                }
            }

            $this->redis = new Predis($params);
            $this->lastStatus = (string) $this->redis->ping(); // "PONG"
        } catch (\Throwable $e) {
            $this->redis = null;
            $this->lastStatus = 'error: ' . $e->getMessage();
        }
    }

    public function enabled(): bool { return $this->redis !== null; }
    public function status(): ?string { return $this->lastStatus; }

    public function get(string $key): mixed
    {
        $this->lastKey = $key;
        if (!$this->redis) { $this->lastHit = null; return null; }
        $val = $this->redis->get($key);
        $this->lastHit = ($val !== null);
        return $val !== null ? json_decode($val, true) : null;
    }

    public function set(string $key, mixed $value, int $ttl = 60): void
    {
        if (!$this->redis) return;
        $this->redis->setex($key, $ttl, json_encode($value));
    }

    public function del(string $key): void
    {
        if (!$this->redis) return;
        $this->redis->del([$key]);
    }

    public function lastHit(): ?bool { return $this->lastHit; }
    public function lastKey(): ?string { return $this->lastKey; }

    public function rawGetString(string $key): ?string
    {
        if (!$this->redis) return null;
        return $this->redis->get($key);
    }

    public function scan(string $pattern = '*', int $limit = 100): array
    {
        if (!$this->redis) return [];
        $cursor = 0;
        $keys = [];
        do {
            [$cursor, $chunk] = $this->redis->scan($cursor, 'MATCH', $pattern, 'COUNT', 50);
            if (is_array($chunk)) {
                foreach ($chunk as $k) {
                    $keys[] = $k;
                    if (count($keys) >= $limit) break 2;
                }
            }
        } while ((int)$cursor !== 0 && count($keys) < $limit);
        return $keys;
    }

    // Cache.php ergänzen
    public function ttl(string $key): ?int {
        if (!$this->redis) return null;
        $t = $this->redis->ttl($key);
        return is_int($t) ? $t : null; // -2=kein Key, -1=ohne TTL
    }

}
