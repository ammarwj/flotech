<?php

namespace App\Support;

use App\Models\Event;
use App\Models\GameMatch;
use App\Models\Team;
use App\Models\Ticket;
use App\Services\LandingStatService;

/**
 * The catalog of counters the landing page's "Proof" strip can show.
 *
 * Code holds the catalog and every default; `landing_stat_settings` holds
 * overrides only — the same split as PlatformSettings, and the opposite of
 * `testimonials`/`faqs` next door where the rows ARE the content. A production
 * database with zero rows is the correct state and renders exactly what this
 * file says.
 *
 * Adding a metric touches this file and nothing else, which is why the resolver
 * is a closure inside the entry rather than a `match ($key)` arm in the service:
 * a forgotten arm does not fail, it silently returns 0.
 */
class LandingMetrics
{
    /**
     * Not a `const`: entries carry closures, which a compile-time literal
     * cannot hold — the same reason PlatformSettings::definitions() sits beside
     * BASE_DEFINITIONS. For the same reason the catalog is NOT serializable:
     * cache the resolved numbers, never `rememberForever` over all().
     *
     * Every resolver is handed the LandingStatService doing the asking, and the
     * two traffic metrics are the reason: they share one aggregate row over
     * `event_view_daily`, so they read it through that instance's memo instead of
     * each running their own sum. Resolvers that need nothing ignore the argument.
     *
     * @return array<string, array{label: string, active: bool, sort: int, resolve: callable(LandingStatService): int}>
     */
    public static function all(): array
    {
        return [
            'tournaments' => [
                'label' => 'Turnamen terselenggara',
                'active' => true,
                'sort' => 10,
                // Events that actually ran. A draft or an open registration is
                // not a tournament that happened, and a cancelled one never was.
                'resolve' => fn (LandingStatService $stats): int => Event::whereIn('status', ['ongoing', 'finished'])->count(),
            ],
            'teams' => [
                'label' => 'Tim terdaftar',
                'active' => true,
                'sort' => 20,
                // Teams that were once accepted; pending and rejected entries
                // never became participants.
                'resolve' => fn (LandingStatService $stats): int => Team::whereIn('status', ['approved', 'disqualified', 'withdrawn'])->count(),
            ],
            'page_views' => [
                // "halaman event", not plain "Pengunjung": the beacon is mounted
                // only on public event pages, never on the landing page itself,
                // so this is event traffic. A number labelled wider than it is
                // becomes a claim.
                'label' => 'Pengunjung halaman event',
                'active' => true,
                'sort' => 30,
                'resolve' => fn (LandingStatService $stats): int => $stats->viewTotals()['views'],
            ],
            'matches' => [
                'label' => 'Pertandingan dimainkan',
                'active' => true,
                'sort' => 40,
                'resolve' => fn (LandingStatService $stats): int => GameMatch::where('status', 'finished')->count(),
            ],
            'tickets' => [
                'label' => 'Tiket terjual',
                // Off by default: sales are still small, and a small number on a
                // strip whose job is reassurance reads as a weakness. The data is
                // untouched and a super admin can switch it back on.
                'active' => false,
                'sort' => 50,
                // Rows in `tickets` only exist for paid orders (TicketService::markPaid),
                // so this is already "sold" without a join.
                'resolve' => fn (LandingStatService $stats): int => Ticket::count(),
            ],
            'visitors' => [
                // NOT distinct humans, and structurally cannot be: `visitor_hash`
                // is HMAC'd with the day as an input (a privacy decision stated in
                // EventViewService::visitorHash()) and uniqued per
                // (event_id, viewed_on). One person opening two events today
                // counts twice, and twice more tomorrow. Hence the label, and
                // hence `page_views` is the one that ships on.
                'label' => 'Kunjungan unik halaman event',
                'active' => false,
                'sort' => 60,
                'resolve' => fn (LandingStatService $stats): int => $stats->viewTotals()['unique_visitors'],
            ],
        ];
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }
}
