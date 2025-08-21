<?php
require __DIR__ . '/../vendor/autoload.php';

use App\{Util, Database, Storage, Cache};

// Hochladegrenze aus ENV (Standard 10 MB)
$maxUpload = Util::bytesFromEnv('MAX_UPLOAD_BYTES', 10 * 1024 * 1024);
ini_set('upload_max_filesize', (string)$maxUpload);
ini_set('post_max_size', (string)max($maxUpload + 1024 * 1024, 12 * 1024 * 1024));

$db = new Database();
$messages = $db->messages; // Installations-/Schema-Meldungen sammeln

$storage = new Storage();
$cache = new Cache();

// Einfacher MIME-Check
function detect_mime(string $file): string
{
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file) ?: 'application/octet-stream';
    finfo_close($finfo);
    return $mime;
}

function allowed_mime(string $mime): bool
{
    return in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
}
