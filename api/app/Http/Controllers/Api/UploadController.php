<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\R2StorageService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;

class UploadController extends Controller
{
    public function __construct(protected R2StorageService $r2) {}

    /**
     * Store an uploaded image and return a directly usable URL. Uses R2 when
     * configured, otherwise the local `public` disk so the URL renders in
     * development too.
     *
     * Everything stored here is re-encoded to WebP. The web app already
     * compresses to WebP before uploading, but that's a convenience, not a
     * guarantee — `compressToWebp()` falls back to the original file when the
     * browser can't produce a WebP blob, and other clients hit this endpoint
     * too. Re-encoding server-side is what actually makes the rule hold.
     */
    public function image(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'image', 'max:5120'], // 5 MB
            'folder' => ['nullable', 'string', 'max:100'],
        ]);

        $file = $request->file('file');
        $folder = trim($request->input('folder', 'images'), '/') ?: 'images';
        $key = $folder.'/'.Str::uuid().'.webp';

        $contents = (string) (new ImageManager(new GdDriver))
            ->decodePath($file->getRealPath())
            // Guard against a client that skipped compression; an image the web
            // app already sized is well under this and passes through untouched.
            ->scaleDown(width: 2000, height: 2000)
            ->encode(new WebpEncoder(quality: 82));

        if (config('r2.key')) {
            // Direct SDK upload; the bucket is exposed via its public r2.dev URL.
            $this->r2->put($key, $contents, 'image/webp');
            $url = $this->r2->publicUrl($key);
        } else {
            Storage::disk('public')->put($key, $contents);
            $url = Storage::disk('public')->url($key);
        }

        return ApiResponse::success(['file_url' => $url, 'key' => $key]);
    }

    /**
     * Issue a presigned URL for a direct-to-R2 upload. When R2 is not
     * configured (dev), returns a mock so the registration flow still works.
     */
    public function sign(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file_name' => ['required', 'string', 'max:255'],
            'content_type' => ['nullable', 'string', 'max:100'],
            'folder' => ['nullable', 'string', 'max:100'],
        ]);

        $folder = trim($validated['folder'] ?? 'uploads', '/');
        $safe = Str::slug(pathinfo($validated['file_name'], PATHINFO_FILENAME));
        $ext = pathinfo($validated['file_name'], PATHINFO_EXTENSION);
        $key = $folder.'/'.Str::uuid().($safe ? "-{$safe}" : '').($ext ? ".{$ext}" : '');

        if (! config('r2.key')) {
            return ApiResponse::success([
                'key' => $key,
                'upload_url' => null,
                'file_url' => 'mock://'.$key,
                'mock' => true,
            ], 'R2 belum dikonfigurasi — mock upload.');
        }

        return ApiResponse::success([
            'key' => $key,
            'upload_url' => $this->r2->presignedUploadUrl($key, $validated['content_type'] ?? null),
            'file_url' => $this->r2->publicUrl($key),
            'mock' => false,
        ]);
    }
}
