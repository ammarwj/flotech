<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\PurgeMediaJob;
use App\Models\Event;
use App\Models\EventPhoto;
use App\Models\EventSponsor;
use App\Models\Organization;
use App\Services\Catalog;
use App\Services\PlanGate;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * An event's photo albums and sponsor logos. Images are uploaded through
 * /uploads/image first; here we only store the resulting URLs.
 *
 * Routes live under organizations/{organization}, so every action declares the
 * path params positionally.
 */
class EventMediaController extends Controller
{
    public function __construct(protected PlanGate $gate) {}

    // ---- Photos ----

    public function photos(Request $request, string $organization, string $event): JsonResponse
    {
        return ApiResponse::success($this->event($request, $event)->photos);
    }

    /**
     * Add photos to an album. Sent in bulk, because the organizer picks several
     * files at once.
     */
    public function storePhotos(Request $request, string $organization, string $event): JsonResponse
    {
        $eventModel = $this->event($request, $event);

        // Order matters. The boolean denies first, so withinLimit()'s
        // "an absent numeric limit means unlimited" can never hand an uncapped
        // gallery to a plan that has no gallery at all.
        if (! $this->gate->allows($eventModel, 'event_gallery')) {
            return ApiResponse::error(
                'Galeri foto tidak tersedia di paket event ini.',
                ['feature' => 'event_gallery'],
                403,
            );
        }

        $data = $request->validate([
            'album' => ['nullable', 'string', 'max:100'],
            // A per-REQUEST abuse guard, not the plan cap: ten requests of five
            // photos each sail straight past it. The plan's cap is on the
            // event's total, checked below.
            'photos' => ['required', 'array', 'min:1', 'max:50'],
            'photos.*.photo_url' => ['required', 'string'],
            'photos.*.caption' => ['nullable', 'string', 'max:255'],
        ]);

        $existing = $eventModel->photos()->count();

        if (! $this->gate->withinLimit($eventModel, 'max_gallery_photos', $existing, count($data['photos']))) {
            $limit = $this->gate->limit($eventModel, 'max_gallery_photos');

            return ApiResponse::error(
                "Paket event ini membatasi {$limit} foto galeri (sudah ada {$existing}).",
                ['feature' => 'max_gallery_photos'],
                403,
            );
        }

        $album = $data['album'] ?? null;
        $next = (int) $eventModel->photos()->where('album', $album)->max('sort_order');

        foreach ($data['photos'] as $photo) {
            $eventModel->photos()->create([
                'album' => $album,
                'photo_url' => $photo['photo_url'],
                'caption' => $photo['caption'] ?? null,
                'sort_order' => ++$next,
            ]);
        }

        return ApiResponse::success(
            $eventModel->photos()->get(),
            count($data['photos']).' foto ditambahkan',
            201,
        );
    }

    public function updatePhoto(Request $request, string $organization, string $photo): JsonResponse
    {
        $model = $this->photo($request, $photo);

        $model->update($request->validate([
            'album' => ['nullable', 'string', 'max:100'],
            'caption' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]));

        return ApiResponse::success($model, 'Foto diperbarui');
    }

    public function destroyPhoto(Request $request, string $organization, string $photo): JsonResponse
    {
        $model = $this->photo($request, $photo);
        $url = $model->photo_url;

        $model->delete();

        PurgeMediaJob::dispatch([$url])->afterCommit();

        return ApiResponse::success(null, 'Foto dihapus');
    }

    // ---- Sponsors ----

    public function sponsors(Request $request, string $organization, string $event): JsonResponse
    {
        return ApiResponse::success($this->event($request, $event)->sponsors);
    }

    public function storeSponsor(Request $request, string $organization, string $event): JsonResponse
    {
        $eventModel = $this->event($request, $event);

        // Only adding is gated. updateSponsor() and destroySponsor() stay open
        // on purpose: a logo that already exists — put there under a plan that
        // allowed it, or before this gate existed — must remain editable and
        // removable. Same reasoning as CertificateController::download().
        if (! $this->gate->allows($eventModel, 'sponsor_logos')) {
            return ApiResponse::error(
                'Logo sponsor tidak tersedia di paket event ini.',
                ['feature' => 'sponsor_logos'],
                403,
            );
        }

        $data = $request->validate($this->sponsorRules());

        $sponsor = $eventModel->sponsors()->create([
            ...$data,
            // Default to the first tier the admin has configured.
            'tier' => $data['tier'] ?? (Catalog::keys('sponsor_tier')[1] ?? 'sponsor'),
            'sort_order' => $data['sort_order'] ?? ((int) $eventModel->sponsors()->max('sort_order') + 1),
        ]);

        return ApiResponse::success($sponsor, 'Sponsor ditambahkan', 201);
    }

    public function updateSponsor(Request $request, string $organization, string $sponsor): JsonResponse
    {
        $model = $this->sponsor($request, $sponsor);

        $model->update($request->validate($this->sponsorRules(partial: true)));

        return ApiResponse::success($model, 'Sponsor diperbarui');
    }

    public function destroySponsor(Request $request, string $organization, string $sponsor): JsonResponse
    {
        $model = $this->sponsor($request, $sponsor);
        $url = $model->logo_url;

        $model->delete();

        PurgeMediaJob::dispatch([$url])->afterCommit();

        return ApiResponse::success(null, 'Sponsor dihapus');
    }

    /**
     * @return array<string, mixed>
     */
    protected function sponsorRules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:255'],
            'logo_url' => [$required, 'string'],
            'website_url' => ['nullable', 'string', 'max:255'],
            'tier' => ['nullable', Rule::in(Catalog::keys('sponsor_tier'))],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    protected function org(Request $request): Organization
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        return $org;
    }

    protected function event(Request $request, string $eventId): Event
    {
        return $this->org($request)->events()->findOrFail($eventId);
    }

    protected function photo(Request $request, string $photoId): EventPhoto
    {
        return EventPhoto::whereHas('event', fn ($q) => $q->where('organization_id', $this->org($request)->id))
            ->findOrFail($photoId);
    }

    protected function sponsor(Request $request, string $sponsorId): EventSponsor
    {
        return EventSponsor::whereHas('event', fn ($q) => $q->where('organization_id', $this->org($request)->id))
            ->findOrFail($sponsorId);
    }
}
