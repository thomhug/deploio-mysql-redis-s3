<?php
namespace App;

use Predis\Client;

class Cache
{
    private ?Client $redis = null;

    public function __construct()
    {
        $url = Util::env('REDIS_URL');
        if ($url) {
            $this->redis = new Client($url);
            return;
        }
        $host = Util::env('REDIS_HOST');
        if ($host) {
            $this->redis = new Client([
                'scheme'   => 'tcp',
                'host'     => $host,
                'port'     => (int)Util::env('REDIS_PORT', 6379),
                'password' => Util::env('REDIS_PASSWORD') ?: null,
            ]);
        }
    }

    public function get(string $key)
    {
        if (!$this->redis) return null;
        $val = $this->redis->get($key);
        return $val !== null ? json_decode($val, true) : null;
    }

    public function set(string $key, $value, int $ttlSeconds = 60): void
    {
        if (!$this->redis) return;
        $this->redis->setex($key, $ttlSeconds, json_encode($value));
    }

    public function del(string $key): void
    {
        if (!$this->redis) return;
        $this->redis->del([$key]);
    }
}