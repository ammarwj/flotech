<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\GameMatch;
use App\Models\Team;
use App\Models\Ticket;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * The four counters in the landing page's "Proof" strip. Public — anyone
 * reading the marketing site sees them.
 *
 * Raw numbers only: "38rb" is presentation and lives in web/lib/landing.ts,
 * the same split as formatPlanFeature().
 *
 * Cached with a TTL, unlike Catalog/PlatformSettings which use
 * rememberForever + an explicit flush. That pattern does not fit here: these
 * numbers move on every registration, ticket and finished match, so the flush
 * hooks would have to sit in a dozen write paths and one of them would be
 * missed. Landing figures being ten minutes stale costs nobody anything.
 */
class PublicStatController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return ApiResponse::success(
            Cache::remember('public_stats', now()->addMinutes(10), fn () => [
                // Events that actually ran. A draft or an open registration is
                // not a tournament that happened, and a cancelled one never was.
                'tournaments' => Event::whereIn('status', ['ongoing', 'finished'])->count(),
                // Teams that were once accepted; pending and rejected entries
                // never became participants.
                'teams' => Team::whereIn('status', ['approved', 'disqualified', 'withdrawn'])->count(),
                // Rows in `tickets` only exist for paid orders (TicketService::markPaid),
                // so this is already "sold" without a join.
                'tickets' => Ticket::count(),
                'matches' => GameMatch::where('status', 'finished')->count(),
            ])
        );
    }
}
