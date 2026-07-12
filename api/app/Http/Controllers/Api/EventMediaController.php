<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventPhoto;
use App\Models\EventSponsor;
use App\Models\Organization;
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

        $data = $request->validate([
            'album' => ['nullable', 'string', 'max:100'],
            'photos' => ['required', 'array', 'min:1', 'max:50'],
            'photos.*.photo_url' => ['required', 'string'],
            'photos.*.caption' => ['nullable', 'string', 'max:255'],
        ]);

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
        $this->photo($request, $photo)->delete();

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

        $data = $request->validate($this->sponsorRules());

        $sponsor = $eventModel->sponsors()->create([
            ...$data,
            'tier' => $data['tier'] ?? 'sponsor',
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
        $this->sponsor($request, $sponsor)->delete();

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
            'tier' => ['nullable', Rule::in(EventSponsor::TIERS)],
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
