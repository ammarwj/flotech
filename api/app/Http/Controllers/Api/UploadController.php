<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\R2StorageService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UploadController extends Controller
{
    public function __construct(protected R2StorageService $r2) {}

    /**
     * Store an (already client-compressed) image and return a directly usable
     * URL. Uses R2 when configured, otherwise the local `public` disk so the
     * URL renders in development too.
     */
    public function image(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'image', 'max:5120'], // 5 MB
            'folder' => ['nullable', 'string', 'max:100'],
        ]);

        $file = $request->file('file');
        $folder = trim($request->input('folder', 'images'), '/') ?: 'images';
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'webp');
        $key = $folder.'/'.Str::uuid().'.'.$ext;
        $contents = file_get_contents($file->getRealPath());

        if (config('r2.key')) {
            // Direct SDK upload; the bucket is exposed via its public r2.dev URL.
            $this->r2->put($key, $contents, $file->getMimeType());
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
