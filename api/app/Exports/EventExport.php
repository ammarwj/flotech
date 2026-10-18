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

    /**
     * The PDF page this export needs, in points: `[0, 0, width, height]`.
     *
     * **The paper grows with the table rather than the table shrinking to fit
     * the paper.** The default was A4 portrait with no `setPaper` at all, which
     * silently cut everything past the eighth column off the right edge — and a
     * sheet that looks complete while missing half the answers is worse than no
     * sheet, because nobody checks for what is not there.
     *
     * Derived from the heading count, which is the only thing that decides
     * whether a table fits: a per-export orientation flag would be a second
     * answer to the same question, set once and then wrong the first time
     * somebody adds a registration field. Race entries carry the event's whole
     * registration form, so their width is not knowable when the class is
     * written.
     *
     * Each column is given `COLUMN_WIDTH` points — enough for a short answer at
     * the sizes export.blade.php sets — with A4 portrait as the floor so a
     * narrow export still prints on ordinary paper. Height stays A4's 842pt
     * landscape-style: it is page height, and more columns do not need a taller
     * page, only a wider one.
     *
     * @return array{0: int, 1: int, 2: float, 3: float}
     */
    public function paper(): array
    {
        // A4 portrait, the floor, and the page height for every size above it.
        $minWidth = 595.28;
        $height = 841.89;

        $width = max(
            $minWidth,
            count($this->headings()) * self::COLUMN_WIDTH + self::PAGE_MARGINS,
        );

        return [0, 0, $width, $height];
    }

    /** Points per column — one short answer at export.blade.php's font size. */
    protected const COLUMN_WIDTH = 62;

    /** The left and right @page margins the template sets, together. */
    protected const PAGE_MARGINS = 64;
}
