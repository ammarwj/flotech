<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Organization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only thing allowed to write public page traffic.
 *
 * Every hit does two writes: a dedup row keyed (event, day, visitor hash) that
 * decides whether this is a *new* visitor today, and an upsert that bumps the
 * daily counters. Both are single statements relying on Postgres' ON CONFLICT,
 * so two visitors landing at the same instant can never lose an increment and
 * no lock or transaction is needed.
 *
 * Reads live here too so the 30-day window and the gap-filling rule are
 * defined exactly once — see trendFrom().
 */
class EventViewService
{
    /** Days shown in every trend chart. */
    public const WINDOW = 30;

    /**
     * Clients that fetch pages without a human looking at them. The beacon is
     * a POST from JavaScript so almost nothing here reaches it, but a UA
     * filter costs one preg_match and keeps preview-fetchers (WhatsApp,
     * Telegram) and uptime monitors out of the numbers.
     */
    private const BOT_PATTERN = '/bot|crawl|spider|slurp|facebookexternalhit|whatsapp|telegram|preview|headless|lighthouse|monitor|curl|wget|python-requests|axios|okhttp/i';

    /**
     * Today in the organizer's zone.
     *
     * The app runs in UTC while everyone using it lives in WIB, so a naive
     * now()->toDateString() rolls the day over at 07:00 local — "today" in the
     * chart would sit half-empty until lunchtime. Same reasoning as
     * WalletService::availableAtFor().
     */
    public function today(): Carbon
    {
        return Carbon::now(config('wallet.timezone'))->startOfDay();
    }

    /**
     * Record one visit. Returns false when the hit was ignored (a bot).
     */
    public function record(Event $event, string $ip, ?string $userAgent): bool
    {
        if ($this->isBot($userAgent)) {
            return false;
        }

        $day = $this->today()->toDateString();
        $hash = $this->visitorHash($ip, $userAgent, $day);

        // Timestamps are bound, not now(): production is Postgres but the test
        // suite runs on SQLite, which has no such function. Binding also keeps
        // these rows on the app's clock, so Carbon::setTestNow() applies.
        $stamp = Carbon::now();

        // 1 = first time we've seen this visitor on this event today, 0 = a
        // repeat visit. ON CONFLICT DO NOTHING makes the check and the claim a
        // single atomic step, so concurrent tabs can't both count as unique.
        $isNewVisitor = DB::affectingStatement(
            'insert into event_view_visitors (id, event_id, viewed_on, visitor_hash, created_at)
             values (?, ?, ?, ?, ?)
             on conflict (event_id, viewed_on, visitor_hash) do nothing',
            [(string) Str::uuid(), $event->id, $day, $hash, $stamp],
        );

        DB::statement(
            'insert into event_view_daily
                 (id, event_id, organization_id, viewed_on, views, unique_visitors, created_at, updated_at)
             values (?, ?, ?, ?, 1, ?, ?, ?)
             on conflict (event_id, viewed_on) do update
                set views           = event_view_daily.views + 1,
                    unique_visitors = event_view_daily.unique_visitors + excluded.unique_visitors,
                    updated_at      = ?',
            [(string) Str::uuid(), $event->id, $event->organization_id, $day, $isNewVisitor, $stamp, $stamp, $stamp],
        );

        return true;
    }

    /**
     * Pseudonymous, day-scoped visitor id.
     *
     * Three deliberate choices:
     *  - The raw IP is never stored or logged; only this digest reaches the DB.
     *  - The day is part of the input, so the same person hashes differently
     *    tomorrow. That is the whole rotation mechanism, and it means the
     *    ledger cannot be used to follow anyone across days — not even by us.
     *  - HMAC keyed with APP_KEY, not a bare sha256: IPv4 is only 2^32 wide,
     *    so an unkeyed digest could be reversed by brute force in hours.
     */
    public function visitorHash(string $ip, ?string $userAgent, string $day): string
    {
        return hash_hmac('sha256', $ip.'|'.($userAgent ?? '').'|'.$day, config('app.key'));
    }

    public function isBot(?string $userAgent): bool
    {
        // No UA at all is a script, not a browser.
        return blank($userAgent) || preg_match(self::BOT_PATTERN, $userAgent) === 1;
    }

    // ---- Reads -------------------------------------------------------------

    /** @return array{views: int, unique_visitors: int} */
    public function totalsForEvent(Event $event): array
    {
        return $this->totalsFrom(DB::table('event_view_daily')->where('event_id', $event->id));
    }

    /** @return list<array{date: string, views: int, unique_visitors: int}> */
    public function trendForEvent(Event $event, int $days = self::WINDOW): array
    {
        return $this->trendFrom(DB::table('event_view_daily')->where('event_id', $event->id), $days);
    }

    /** @return array{views: int, unique_visitors: int} */
    public function totalsForOrganization(Organization $org): array
    {
        return $this->totalsFrom(DB::table('event_view_daily')->where('organization_id', $org->id));
    }

    /** @return list<array{date: string, views: int, unique_visitors: int}> */
    public function trendForOrganization(Organization $org, int $days = self::WINDOW): array
    {
        return $this->trendFrom(DB::table('event_view_daily')->where('organization_id', $org->id), $days);
    }

    /** @return array{views: int, unique_visitors: int} */
    public function platformTotals(): array
    {
        return $this->totalsFrom(DB::table('event_view_daily'));
    }

    /** @return list<array{date: string, views: int, unique_visitors: int}> */
    public function platformTrend(int $days = self::WINDOW): array
    {
        return $this->trendFrom(DB::table('event_view_daily'), $days);
    }

    /**
     * Per-event totals for one organization, busiest first. Events with no
     * traffic yet are included with zeroes — an organizer needs to see that a
     * page is getting nothing, which a plain join would hide.
     *
     * @return list<array<string, mixed>>
     */
    public function eventBreakdownForOrganization(Organization $org): array
    {
        return DB::table('events')
            ->leftJoin('event_view_daily', 'event_view_daily.event_id', '=', 'events.id')
            ->where('events.organization_id', $org->id)
            ->groupBy('events.id', 'events.name', 'events.slug', 'events.status')
            ->orderByDesc('views')
            ->orderBy('events.name')
            ->get([
                'events.id as event_id',
                'events.name',
                'events.slug',
                'events.status',
                DB::raw('coalesce(sum(event_view_daily.views), 0) as views'),
                DB::raw('coalesce(sum(event_view_daily.unique_visitors), 0) as unique_visitors'),
            ])
            ->map(fn ($row) => [
                'event_id' => $row->event_id,
                'name' => $row->name,
                'slug' => $row->slug,
                'status' => $row->status,
                'views' => (int) $row->views,
                'unique_visitors' => (int) $row->unique_visitors,
            ])
            ->all();
    }

    /**
     * Platform-wide traffic per organization, busiest first.
     *
     * @return list<array<string, mixed>>
     */
    public function breakdownByOrganization(int $limit = 20): array
    {
        return DB::table('event_view_daily')
            ->join('organizations', 'organizations.id', '=', 'event_view_daily.organization_id')
            ->groupBy('organizations.id', 'organizations.name', 'organizations.slug')
            ->orderByDesc('views')
            ->limit($limit)
            ->get([
                'organizations.id as organization_id',
                'organizations.name',
                'organizations.slug',
                DB::raw('sum(event_view_daily.views) as views'),
                DB::raw('sum(event_view_daily.unique_visitors) as unique_visitors'),
                DB::raw('count(distinct event_view_daily.event_id) as events_count'),
            ])
            ->map(fn ($row) => [
                'organization_id' => $row->organization_id,
                'name' => $row->name,
                'slug' => $row->slug,
                'views' => (int) $row->views,
                'unique_visitors' => (int) $row->unique_visitors,
                'events_count' => (int) $row->events_count,
            ])
            ->all();
    }

    /**
     * Platform-wide traffic per event, busiest first. Passing an organization
     * id narrows it, which is how the admin table drills down from a row of
     * breakdownByOrganization() without a separate endpoint.
     *
     * @return list<array<string, mixed>>
     */
    public function breakdownByEvent(?string $organizationId = null, int $limit = 20): array
    {
        return DB::table('event_view_daily')
            ->join('events', 'events.id', '=', 'event_view_daily.event_id')
            ->join('organizations', 'organizations.id', '=', 'event_view_daily.organization_id')
            ->when($organizationId, fn ($q, $id) => $q->where('event_view_daily.organization_id', $id))
            ->groupBy('events.id', 'events.name', 'events.slug', 'organizations.id', 'organizations.name')
            ->orderByDesc('views')
            ->limit($limit)
            ->get([
                'events.id as event_id',
                'events.name',
                'events.slug',
                'organizations.id as organization_id',
                'organizations.name as organization_name',
                DB::raw('sum(event_view_daily.views) as views'),
                DB::raw('sum(event_view_daily.unique_visitors) as unique_visitors'),
            ])
            ->map(fn ($row) => [
                'event_id' => $row->event_id,
                'name' => $row->name,
                'slug' => $row->slug,
                'organization_id' => $row->organization_id,
                'organization_name' => $row->organization_name,
                'views' => (int) $row->views,
                'unique_visitors' => (int) $row->unique_visitors,
            ])
            ->all();
    }

    // ---- Internals ---------------------------------------------------------

    /**
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return array{views: int, unique_visitors: int}
     */
    private function totalsFrom($query): array
    {
        $row = $query->first([
            DB::raw('coalesce(sum(views), 0) as views'),
            DB::raw('coalesce(sum(unique_visitors), 0) as unique_visitors'),
        ]);

        return [
            'views' => (int) ($row->views ?? 0),
            'unique_visitors' => (int) ($row->unique_visitors ?? 0),
        ];
    }

    /**
     * A trend is always exactly $days consecutive points ending today.
     *
     * Days with no traffic have no row, so they must be filled with zeroes
     * here. Handing the chart only the days that happened would squash the
     * time axis and draw a trend that never occurred.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return list<array{date: string, views: int, unique_visitors: int}>
     */
    private function trendFrom($query, int $days): array
    {
        $end = $this->today();
        $start = $end->copy()->subDays($days - 1);

        $rows = (clone $query)
            ->whereBetween('viewed_on', [$start->toDateString(), $end->toDateString()])
            ->groupBy('viewed_on')
            ->get([
                'viewed_on',
                DB::raw('sum(views) as views'),
                DB::raw('sum(unique_visitors) as unique_visitors'),
            ]);

        return $this->fillGaps($rows, $start, $end);
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return list<array{date: string, views: int, unique_visitors: int}>
     */
    private function fillGaps(Collection $rows, Carbon $start, Carbon $end): array
    {
        $byDate = $rows->keyBy(fn ($row) => Carbon::parse($row->viewed_on)->toDateString());

        $points = [];
        $day = $start->copy();

        while ($day->lessThanOrEqualTo($end)) {
            $date = $day->toDateString();
            $row = $byDate->get($date);

            $points[] = [
                'date' => $date,
                'views' => (int) ($row->views ?? 0),
                'unique_visitors' => (int) ($row->unique_visitors ?? 0),
            ];

            $day->addDay();
        }

        return $points;
    }
}
