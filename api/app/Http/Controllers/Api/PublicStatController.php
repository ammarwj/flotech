<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LandingStatService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * The counters in the landing page's "Proof" strip. Public — anyone reading the
 * marketing site sees them.
 *
 * A list, not a flat object: which counters show, what they are called and in
 * what order are all super-admin settings now, so the client renders whatever it
 * receives. The catalog and its defaults live in App\Support\LandingMetrics.
 *
 * Raw numbers only: "38rb" is presentation and lives in web/lib/landing.ts,
 * the same split as formatPlanFeature().
 *
 * Cached with a TTL, unlike Catalog/PlatformSettings which use
 * rememberForever + an explicit flush. That pattern does not fit here: these
 * numbers move on every registration, ticket and finished match, so the flush
 * hooks would have to sit in a dozen write paths and one of them would be
 * missed. Landing figures being ten minutes stale costs nobody anything. (An
 * admin editing the catalog DOES flush — see LandingStatService::put() — so the
 * TTL only ever delays numbers, never a setting.)
 *
 * The TTL stopped being merely cosmetic once a traffic metric joined the strip:
 * `event_view_daily` is summed with no WHERE clause, which no index helps, so
 * this cache is what stands between a cold landing hit and a full scan. Don't
 * shorten it.
 */
class PublicStatController extends Controller
{
    public function __invoke(LandingStatService $stats): JsonResponse
    {
        return ApiResponse::success(
            Cache::remember(
                LandingStatService::CACHE_KEY,
                now()->addMinutes(10),
                fn () => $stats->publicList(),
            )
        );
    }
}
