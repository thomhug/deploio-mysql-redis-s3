<?php
namespace App;

use PDO;
use PDOException;

class Database
{
    public PDO $pdo;
    public string $driver;
    public array $messages = [];

    public function __construct()
    {
        [$dsn, $user, $pass] = $this->buildDsn();

        // Debug-Ausgabe ins Error-Log
        error_log("DSN: " . $dsn);
        error_log("User: " . $user);
        error_log("Pass: " . $pass);

        // Verbindungsversuch; bei "Datenbank existiert nicht" optional versuchen zu erstellen
        try {

            // Basisoptionen
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ];

            // SSL für MySQL, falls ENV gesetzt
            if (str_starts_with($dsn, 'mysql:')) {
                $caPath = \App\Util::env('DB_SSL_CA_PATH');
                $caPem  = \App\Util::env('DB_SSL_CA_PEM');
                $caB64  = \App\Util::env('DB_SSL_CA_B64');
                if (!$caPem && $caB64) {
                    $caPem = base64_decode($caB64, true) ?: null;
                }
                if (!$caPath && $caPem) {
                    $caPath = '/tmp/db-ca.pem';
                    file_put_contents($caPath, $caPem);
                }
                if ($caPath && is_file($caPath)) {
                    $options[\PDO::MYSQL_ATTR_SSL_CA] = $caPath;
                }
            }

            $this->pdo = new PDO($dsn, $user, $pass, $options); 


        } catch (PDOException $e) {
            $msg = $e->getMessage();
            $allowCreate = Util::boolEnv('ALLOW_DB_CREATE', false);
            if ($allowCreate && $this->canCreateDatabase($msg)) {
                $this->createDatabase($user, $pass);
                $this->pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
                $this->messages[] = 'Neue Datenbank angelegt.';
            } else {
                throw $e;
            }
        }

        $this->driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $this->ensureSchema();
    }

    private function buildDsn(): array
    {
        $databaseUrl = Util::env('DATABASE_URL');
        $user = Util::env('DB_USER');
        $pass = Util::env('DB_PASS');
        if ($databaseUrl) {
            $parts = parse_url($databaseUrl);
            if ($parts === false) throw new \RuntimeException('Ungültige DATABASE_URL');
            $scheme = $parts['scheme'] ?? '';
            $host   = $parts['host'] ?? 'localhost';
            $port   = $parts['port'] ?? null;
            $db     = ltrim($parts['path'] ?? '', '/');
            $user   = $parts['user'] ?? $user;
            $pass   = $parts['pass'] ?? $pass;

            if (in_array($scheme, ['mysql', 'mariadb'])) {
                $charset = Util::env('DB_CHARSET', 'utf8mb4');
                $dsn = "mysql:host={$host};" . ($port ? "port={$port};" : '') . "dbname={$db};charset={$charset}";
                return [$dsn, $user, $pass];
            }
            if (in_array($scheme, ['postgres', 'postgresql', 'pgsql'])) {
                $dsn = "pgsql:host={$host};" . ($port ? "port={$port};" : '') . "dbname={$db}";
                return [$dsn, $user, $pass];
            }
            throw new \RuntimeException('DATABASE_URL Schema nicht unterstützt: ' . $scheme);
        }

        // Fallback über DB_DSN
        $dsn = Util::env('DB_DSN');
        if (!$dsn) {
            throw new \RuntimeException('Bitte DATABASE_URL oder DB_DSN/DB_USER/DB_PASS setzen.');
        }
        return [$dsn, $user, $pass];
    }

    private function canCreateDatabase(string $errorMessage): bool
    {
        $em = strtolower($errorMessage);
        return str_contains($em, 'unknown database')
            || str_contains($em, 'does not exist')
            || str_contains($em, 'database "') && str_contains($em, '" does not exist');
    }

    private function createDatabase(string $user, string $pass): void
    {
        $databaseUrl = Util::env('DATABASE_URL');
        $parts = parse_url($databaseUrl);
        $scheme = $parts['scheme'] ?? '';
        $host   = $parts['host'] ?? 'localhost';
        $port   = $parts['port'] ?? null;
        $db     = ltrim($parts['path'] ?? '', '/');

        if (in_array($scheme, ['mysql', 'mariadb'])) {
            $charset = Util::env('DB_CHARSET', 'utf8mb4');
            $dsn = "mysql:host={$host};" . ($port ? "port={$port};" : '') . "charset={$charset}";
            $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            //$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db}` CHARACTER SET {$charset} COLLATE {$charset}_general_ci");
            return;
        }
        if (in_array($scheme, ['postgres', 'postgresql', 'pgsql'])) {
            $dsn = "pgsql:host={$host};" . ($port ? "port={$port};" : '') . "dbname=postgres";
            $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec("CREATE DATABASE \"{$db}\"");
            return;
        }
        throw new \RuntimeException('Automatisches DB-Anlegen nicht unterstützt für: '.$scheme);
    }

    private function ensureSchema(): void
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $sql = <<<SQL
CREATE TABLE IF NOT EXISTS images (
  id INT AUTO_INCREMENT PRIMARY KEY,
  object_key VARCHAR(512) NOT NULL UNIQUE,
  original_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  size BIGINT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
            $this->pdo->exec($sql);
            $this->messages[] = 'Schema geprüft (MySQL).';
        } elseif ($driver === 'pgsql') {
            $sql = <<<SQL
CREATE TABLE IF NOT EXISTS images (
  id SERIAL PRIMARY KEY,
  object_key TEXT NOT NULL UNIQUE,
  original_name TEXT NOT NULL,
  mime_type TEXT NOT NULL,
  size BIGINT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
SQL;
            $this->pdo->exec($sql);
            $this->messages[] = 'Schema geprüft (PostgreSQL).';
        } else {
            throw new \RuntimeException('Nicht unterstützter PDO-Treiber: ' . $driver);
        }
    }

    public function insertImage(string $key, string $original, string $mime, int $size): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO images (object_key, original_name, mime_type, size) VALUES (?, ?, ?, ?)');
        $stmt->execute([$key, $original, $mime, $size]);
    }

    public function deleteImageById(int $id): ?array
    {
        $this->pdo->beginTransaction();
        $row = $this->getImageById($id, forUpdate: true);
        if ($row) {
            $del = $this->pdo->prepare('DELETE FROM images WHERE id = ?');
            $del->execute([$id]);
        }
        $this->pdo->commit();
        return $row ?: null;
    }

    public function getImageById(int $id, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT * FROM images WHERE id = ?' . ($forUpdate ? ' FOR UPDATE' : '');
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function listImages(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM images ORDER BY created_at DESC, id DESC LIMIT 500');
        return $stmt->fetchAll();
    }
}
