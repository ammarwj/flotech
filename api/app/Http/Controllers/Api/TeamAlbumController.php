<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Organization;
use App\Services\TeamAlbumService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Printable player album — a photo grid of a team's roster (plus bench),
 * one team per PDF page. Plain `tenant`, no plan gate: same reasoning as the
 * referee/personnel routes next to it — an operator already sees every
 * player's photo through the roster, so re-laying it out for print isn't a
 * new entitlement.
 */
class TeamAlbumController extends Controller
{
    public function __construct(protected TeamAlbumService $albums) {}

    /** One team's album. */
    public function team(Request $request, string $organization, string $event, string $team): Response
    {
        $model = $this->findEvent($request, $event);
        $team = $model->teams()->with(['players', 'officials'])->findOrFail($team);

        return $this->albums
            ->build($model, collect([$team]))
            ->download('album-'.$this->slug($team->name).'.pdf');
    }

    /** Every approved team in the event, one PDF, one team per page. */
    public function event(Request $request, string $organization, string $event): Response
    {
        $model = $this->findEvent($request, $event);

        $teams = $model->teams()
            ->where('status', 'approved')
            ->when($request->query('category_id'), fn ($q, $id) => $q->where('category_id', $id))
            ->with(['players', 'officials'])
            ->orderBy('name')
            ->get();

        if ($teams->isEmpty()) {
            return ApiResponse::error('Belum ada tim yang disetujui untuk event ini.', null, 404);
        }

        return $this->albums
            ->build($model, $teams)
            ->download('album-'.$this->slug($model->slug).'.pdf');
    }

    protected function findEvent(Request $request, string $id): Event
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        return $org->events()->where('id', $id)->firstOrFail();
    }

    /** Filenames can't carry slashes, and a team name might have one typed in. */
    protected function slug(string $value): string
    {
        return str_replace('/', '-', $value);
    }
}
