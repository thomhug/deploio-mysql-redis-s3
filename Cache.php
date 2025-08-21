<?php
namespace App;

use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use DateInterval;

class Storage
{
    public S3Client $s3;
    public string $bucket;

    public function __construct()
    {
        $this->s3 = new S3Client([
            'version' => 'latest',
            'region'  => Util::env('S3_REGION', 'auto'),
            'endpoint'=> Util::env('S3_ENDPOINT'),
            'use_path_style_endpoint' => Util::boolEnv('S3_USE_PATH_STYLE', true),
            'credentials' => [
                'key'    => Util::env('S3_ACCESS_KEY'),
                'secret' => Util::env('S3_SECRET_KEY'),
            ],
        ]);
        $this->bucket = Util::env('S3_BUCKET');
        if (!$this->bucket) {
            throw new \RuntimeException('S3_BUCKET ist nicht gesetzt.');
        }
    }

    public function put(string $key, string $tmpFile, string $mime): void
    {
        $this->s3->putObject([
            'Bucket' => $this->bucket,
            'Key'    => $key,
            'Body'   => fopen($tmpFile, 'rb'),
            'ContentType' => $mime,
            'ACL'    => 'private',
        ]);
    }

    public function delete(string $key): void
    {
        $this->s3->deleteObject([
            'Bucket' => $this->bucket,
            'Key'    => $key,
        ]);
    }

    public function presignedUrl(string $key, string $expires = 'PT15M'): string
    {
        $cmd = $this->s3->getCommand('GetObject', [
            'Bucket' => $this->bucket,
            'Key'    => $key,
        ]);
        $request = $this->s3->createPresignedRequest($cmd, new \DateTimeImmutable($expires === 'auto' ? '+15 minutes' : ('+' . (new DateInterval($expires))->format('%i minutes'))));
        // Der obige DateInterval-Teil ist unnötig komplex; einfacher:
        $request = $this->s3->createPresignedRequest($cmd, '+15 minutes');
        return (string)$request->getUri();
    }
}
