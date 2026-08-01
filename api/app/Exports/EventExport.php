<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * One export = a title, a header row, and rows of scalars.
 *
 * Everything an exporter needs to differ on is those three things, so they are
 * all a subclass supplies. Keeping the shape this narrow is what lets the same
 * data drive both xlsx (through this class) and PDF (through
 * resources/views/pdf/export.blade.php) without either format growing its own
 * idea of what the numbers are.
 *
 * Subclasses must not compute anything themselves: standings come from
 * StandingService and the leaderboard from PlayerStatService, the same services
 * the screen reads. An exporter that re-derived them would be a second answer
 * to a question the app already answers, and the two would drift.
 */
abstract class EventExport implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    /** @return list<string> */
    abstract public function headings(): array;

    /** @return list<list<string|int|float|null>> */
    abstract public function rows(): array;

    abstract public function title(): string;

    /** Filename stem, without extension. */
    abstract public function slug(): string;

    /** @return list<list<string|int|float|null>> */
    public function array(): array
    {
        return $this->rows();
    }
}
