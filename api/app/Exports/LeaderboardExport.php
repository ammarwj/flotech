<?php

namespace App\Exports;

use App\Models\EventCategory;
use App\Services\PlayerStatService;

/**
 * Per-player numbers for one category, straight from PlayerStatService.
 *
 * The stat columns are the sport's own (Catalog::statColumns), so a sport an
 * admin adds tomorrow exports its own columns without a deploy — and the header
 * row cannot drift from the values under it, because both come from the same
 * payload.
 */
class LeaderboardExport extends EventExport
{
    /** @var array{columns: list<array<string, mixed>>, primary: string, rows: list<array<string, mixed>>} */
    private array $data;

    public function __construct(
        private EventCategory $category,
        PlayerStatService $stats,
    ) {
        $this->data = $stats->leaderboard($category);
    }

    public function title(): string
    {
        return 'Statistik Pemain';
    }

    public function slug(): string
    {
        return 'statistik-'.$this->category->slug;
    }

    /** @return list<string> */
    public function headings(): array
    {
        return [
            '#',
            'Pemain',
            'No.',
            'Tim',
            ...array_map(fn ($c) => $c['label'], $this->data['columns']),
        ];
    }

    /** @return list<list<string|int|float|null>> */
    public function rows(): array
    {
        return array_map(fn (array $r) => [
            $r['rank'] ?? '',
            $r['player_name'] ?? '—',
            $r['jersey_number'] ?? '—',
            $r['team_name'] ?? '—',
            ...array_map(fn ($c) => (int) ($r['stats'][$c['key']] ?? 0), $this->data['columns']),
        ], $this->data['rows']);
    }
}
