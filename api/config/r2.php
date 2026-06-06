<?php

/*
|--------------------------------------------------------------------------
| Cloudflare R2 configuration
|--------------------------------------------------------------------------
| S3-compatible object storage. Drives App\Services\R2StorageService, which
| issues signed upload/download URLs so clients upload straight to R2.
*/

return [
    'key' => env('R2_ACCESS_KEY_ID'),
    'secret' => env('R2_SECRET_ACCESS_KEY'),
    'region' => env('R2_REGION', 'auto'),
    'bucket' => env('R2_BUCKET', 'flo-event-storage'),
    'endpoint' => env('R2_ENDPOINT'),
    'public_url' => env('R2_PUBLIC_URL'),

    // Signed URL lifetimes (PRD §6.7).
    'upload_url_ttl' => '+15 minutes',
    'download_url_ttl' => '+60 minutes',
];
