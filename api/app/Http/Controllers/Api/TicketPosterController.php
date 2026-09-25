<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Organization;
use App\Services\PlanGate;
use App\Services\TicketPosterService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The printable QR poster that sends a walk-up buyer to an event's ticket shop.
 *
 * Two refusals, and they are not the same one twice:
 *
 *  - **`qr_tickets`**, 403 with `errors.feature`, exactly like every other
 *    organizer-side ticket door (TicketCategoryController, ScanController). A
 *    poster for an event that cannot sell tickets points at a page that refuses
 *    the purchase — printing it is worse than refusing it, because the refusal
 *    then happens in front of a queue at the venue.
 *  - **`draft`**, 422. `ResolvesPublicEvent::resolve()` 404s drafts, so the QR
 *    would be a dead link the moment it left the printer, and a poster is the
 *    one artefact here that cannot be corrected after the fact. The dashboard
 *    guards the button the same way; this is the safety net, because an event
 *    can go back to draft after the page rendered.
 *
 * Behind plain `tenant` like TeamAlbumController, not `org.admin`: the poster
 * carries no buyer data and no money moves through it — it is the public
 * ticket URL, laid out for a wall. An operator staffing the gate is exactly who
 * reprints one that fell off.
 */
class TicketPosterController extends Controller
{
    public function __construct(
        protected PlanGate $gate,
        protected TicketPosterService $posters,
    ) {}

    public function show(Request $request, string $organization, string $event): Response
    {
        $model = $this->findEvent($request, $event);

        if (! $this->gate->allows($model, 'qr_tickets')) {
            return ApiResponse::error(
                'Fitur tiket tidak tersedia di paket event ini.',
                ['feature' => 'qr_tickets'],
                403,
            );
        }

        if ($model->status === 'draft') {
            return ApiResponse::error(
                'Halaman event masih draf, jadi QR-nya belum bisa dibuka pembeli. Terbitkan eventnya dulu.',
                null,
                422,
            );
        }

        return $this->posters
            ->build($model)
            ->download('qr-tiket-'.Str::slug($model->slug).'.pdf');
    }

    protected function findEvent(Request $request, string $id): Event
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        return $org->events()->with(['plan.features', 'organization'])->findOrFail($id);
    }
}
