<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\R2StorageService;
use App\Support\ApiResponse;
use App\Support\UploadLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Encoders\IcoEncoder;
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
        UploadLimits::guard($request);
        $request->validate([
            'file' => ['required', 'file', 'image', 'max:'.UploadLimits::MAX_KB],
            'folder' => ['nullable', 'string', 'max:100'],
        ], ['file.max' => UploadLimits::maxMessage()]);

        $file = $request->file('file');
        $folder = trim($request->input('folder', 'images'), '/') ?: 'images';
        $key = $folder.'/'.Str::uuid().'.webp';

        $contents = (string) (new ImageManager(new GdDriver))
            ->decodePath($file->getRealPath())
            // Guard against a client that skipped compression; an image the web
            // app already sized is well under this and passes through untouched.
            ->scaleDown(width: 2000, height: 2000)
            ->encode(new WebpEncoder(quality: 82));

        return ApiResponse::success($this->store($key, $contents, 'image/webp'));
    }

    /**
     * The platform's favicon, stored as a real .ico.
     *
     * Separate from image() because it is the one upload GD cannot produce:
     * Intervention ships an IcoEncoder for the Imagick driver only (see the
     * imagick install in api/Dockerfile). Everything else still goes through GD
     * and WebP — this endpoint is the only Imagick caller in the app.
     *
     * Behind `superadmin`, unlike the three uploads around it. Those are public
     * because the public registration form posts to them; nothing but the admin
     * CMS has any reason to reach this one.
     */
    public function favicon(Request $request): JsonResponse
    {
        UploadLimits::guard($request);
        $request->validate([
            'file' => ['required', 'file', 'image', 'max:'.UploadLimits::MAX_KB],
        ], ['file.max' => UploadLimits::maxMessage()]);

        $contents = (string) (new ImageManager(new ImagickDriver))
            ->decodePath($request->file('file')->getRealPath())
            // cover(), not scaleDown(): an icon is square, and a browser given a
            // rectangle squashes it rather than letterboxing.
            ->cover(64, 64)
            ->encode(new IcoEncoder);

        return ApiResponse::success($this->store('platform/'.Str::uuid().'.ico', $contents, 'image/x-icon'));
    }

    /**
     * Put the bytes somewhere they can be served from, and say where.
     *
     * R2 in production, the local `public` disk otherwise so uploads render in
     * development too.
     *
     * @return array{file_url: string, key: string}
     */
    protected function store(string $key, string $contents, string $mime): array
    {
        if (config('r2.key')) {
            // Direct SDK upload; the bucket is exposed via its public r2.dev URL.
            // Immutable: every key here carries a UUID, so replacing an image
            // mints a new URL rather than new bytes behind the old one.
            $this->r2->put($key, $contents, $mime, immutable: true);
            $url = $this->r2->publicUrl($key);
        } else {
            Storage::disk('public')->put($key, $contents);
            $url = Storage::disk('public')->url($key);
        }

        return ['file_url' => $url, 'key' => $key];
    }

    /**
     * Store a registration document — a KTP scan, a mandate letter.
     *
     * Documents used to go through sign() below, which validates neither the
     * type nor the size of what it hands out a URL for. This one does both, and
     * turns an accepted image into WebP the same way image() does. That is what
     * makes "images become WebP, 5 MB maximum" a rule rather than a hope: a
     * browser that cannot produce a WebP blob falls back to the original file,
     * and other clients post here directly.
     *
     * A PDF passes through as-is. There is nothing to re-encode, and it is the
     * format an organizer asking for a signed letter actually wants.
     */
    public function document(Request $request): JsonResponse
    {
        UploadLimits::guard($request);
        $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:'.UploadLimits::MAX_KB],
            'folder' => ['nullable', 'string', 'max:100'],
        ], ['file.max' => UploadLimits::maxMessage()]);

        $file = $request->file('file');
        $folder = trim($request->input('folder', 'documents'), '/') ?: 'documents';
        $isPdf = strtolower($file->getClientOriginalExtension()) === 'pdf'
            || $file->getMimeType() === 'application/pdf';

        if ($isPdf) {
            $contents = (string) file_get_contents($file->getRealPath());
            $mime = 'application/pdf';
            $ext = 'pdf';
        } else {
            $contents = (string) (new ImageManager(new GdDriver))
                ->decodePath($file->getRealPath())
                ->scaleDown(width: 2000, height: 2000)
                ->encode(new WebpEncoder(quality: 82));
            $mime = 'image/webp';
            $ext = 'webp';
        }

        $stored = $this->store($folder.'/'.Str::uuid().'.'.$ext, $contents, $mime);

        return ApiResponse::success([
            ...$stored,
            // The stored name, not the one the browser sent: it is what the
            // extension check in TeamRosterService reads, and an image renamed
            // by the re-encode must not still claim to be a .png.
            'file_name' => Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)).'.'.$ext,
            'size' => strlen($contents),
        ]);
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
