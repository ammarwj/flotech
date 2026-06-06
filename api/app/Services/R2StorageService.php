<?php

namespace App\Services;

use Aws\S3\S3Client;
use Illuminate\Support\Facades\Storage;

/**
 * Thin wrapper around Cloudflare R2 (S3-compatible).
 *
 * Public reads go through the CDN URL; private/upload access uses presigned
 * URLs so clients transfer files directly to R2 (saves VPS bandwidth, PRD §6.7).
 */
class R2StorageService
{
    protected S3Client $client;

    protected string $bucket;

    public function __construct()
    {
        $this->bucket = (string) config('r2.bucket');

        $this->client = new S3Client([
            'version' => 'latest',
            'region' => config('r2.region', 'auto'),
            'endpoint' => config('r2.endpoint'),
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key' => config('r2.key'),
                'secret' => config('r2.secret'),
            ],
        ]);
    }

    /**
     * Presigned URL for a client to PUT a file directly to R2.
     */
    public function presignedUploadUrl(string $key, ?string $contentType = null): string
    {
        $command = $this->client->getCommand('PutObject', array_filter([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'ContentType' => $contentType,
        ]));

        return (string) $this->client
            ->createPresignedRequest($command, config('r2.upload_url_ttl'))
            ->getUri();
    }

    /**
     * Presigned URL for downloading a private object (e.g. registration docs).
     */
    public function presignedDownloadUrl(string $key): string
    {
        $command = $this->client->getCommand('GetObject', [
            'Bucket' => $this->bucket,
            'Key' => $key,
        ]);

        return (string) $this->client
            ->createPresignedRequest($command, config('r2.download_url_ttl'))
            ->getUri();
    }

    /**
     * Public CDN URL for an object (logos, banners, generated certificates).
     */
    public function publicUrl(string $key): string
    {
        return rtrim((string) config('r2.public_url'), '/').'/'.ltrim($key, '/');
    }

    /**
     * Underlying Flysystem disk for server-side operations.
     */
    public function disk()
    {
        return Storage::disk('r2');
    }

    public function delete(string $key): bool
    {
        return $this->disk()->delete($key);
    }
}
