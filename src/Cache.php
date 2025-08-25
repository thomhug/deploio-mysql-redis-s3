<?php
namespace App;

use Predis\Client as Predis;
use Predis\Response\Status;

class Cache
{
    private ?Predis $redis = null;
    private ?string $lastStatus = null;

    public function __construct()
    {
        // 1) URL first (z.B. rediss://:pass@host:6380/0)
        $url = Util::env('REDIS_URL');

        // 2) Oder Einzelwerte
        $host = Util::env('REDIS_HOST');
        $opts = null;

        if ($url) {
            $opts = $url;
        } elseif ($host) {
            $opts = [
                'scheme'   => Util::boolEnv('REDIS_TLS', false) ? 'tls' : 'tcp',
                'host'     => $host,
                'port'     => (int) Util::env('REDIS_PORT', 6379),
                'password' => Util::env('REDIS_PASSWORD') ?: null,
                'database' => (int) Util::env('REDIS_DB', 0),
            ];
        }

        if ($opts) {
            try {
                $this->redis = new Predis($opts);
                $pong = $this->redis->ping();
                $this->lastStatus = ($pong instanceof Status) ? (string) $pong : (string) $pong; // "PONG"
            } catch (\Throwable $e) {
                $this->redis = null;
                $this->lastStatus = 'error: ' . $e->getMessage();
            }
        }
    }

    public function enabled(): bool { return $this->redis !== null; }
    public function status(): ?string { return $this->lastStatus; }

    public function get(string $key): mixed
    {
        if (!$this->redis) return null;
        $val = $this->redis->get($key);
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
}