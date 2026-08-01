<?php

namespace App\Exports;

use App\Models\EventCategory;
use App\Services\StandingService;

/**
 * The league table for one category, straight from StandingService.
 *
 * Not recomputed here. The service already decides which fixtures count
 * (finished + confirmed), how hybrid groups are ranked, and how ties break —
 * duplicating any of that would give the spreadsheet a different table from the
 * screen, and the spreadsheet is the one that gets printed and argued over.
 */
class StandingsExport extends EventExport
{
    public function __construct(
        private EventCategory $category,
        private StandingService $standings,
    ) {}

    public function title(): string
    {
        return 'Klasemen';
    }

    public function slug(): string
    {
        return 'klasemen-'.$this->category->slug;
    }

    /** @return list<string> */
    public function headings(): array
    {
        return ['#', 'Grup', 'Tim', 'Main', 'M', 'S', 'K', 'GM', 'GK', 'SG', 'Poin', 'Fair Play'];
    }

    /** @return list<list<string|int|float|null>> */
    public function rows(): array
    {
        return array_map(fn (array $r) => [
            $r['rank'] ?? '',
            $r['group_name'] ?? '—',
            $r['team']['name'] ?? '—',
            $r['played'] ?? 0,
            $r['won'] ?? 0,
            $r['drawn'] ?? 0,
            $r['lost'] ?? 0,
            $r['goals_for'] ?? 0,
            $r['goals_against'] ?? 0,
            $r['goal_diff'] ?? 0,
            $r['points'] ?? 0,
            $r['fair_play'] ?? 0,
        ], $this->standings->compute($this->category));
    }
}
