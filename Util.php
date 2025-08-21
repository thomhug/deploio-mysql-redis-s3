<?php
namespace App;

class Util
{
    public static function env(string $key, $default = null)
    {
        $v = getenv($key);
        return $v === false ? $default : $v;
    }

    public static function boolEnv(string $key, bool $default = false): bool
    {
        $v = self::env($key);
        if ($v === null) return $default;
        return filter_var($v, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    public static function bytesFromEnv(string $key, int $default): int
    {
        $v = self::env($key);
        if ($v === null) return $default;
        // Akzeptiert z.B. "10M", "2G"
        if (preg_match('/^(\d+)([KMG])?$/i', $v, $m)) {
            $n = (int)$m[1];
            $mult = strtoupper($m[2] ?? '');
            return match ($mult) {
                'K' => $n * 1024,
                'M' => $n * 1024 * 1024,
                'G' => $n * 1024 * 1024 * 1024,
                default => $n,
            };
        }
        return $default;
    }

    public static function csrfToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function checkCsrf(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $ok = isset($_POST['csrf']) && hash_equals($_SESSION['csrf'] ?? '', (string)$_POST['csrf']);
        if (!$ok) {
            http_response_code(400);
            echo 'CSRF-Überprüfung fehlgeschlagen.';
            exit;
        }
    }
}
