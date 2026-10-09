<?php

namespace App\Http\Controllers\Concerns;

use App\Models\TicketCategory;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * The day rules shared by every door that issues tickets.
 *
 * Two doors sell now — the public shop and the organizer's box office — and
 * both have to resolve the same three day modes and refuse on the same per-date
 * capacity. Copied into each controller they would drift, and the drift would
 * read as a date that is bookable through one door and sold out through the
 * other. Same reasoning as `ValidatesTicketDays` owning the validation rules.
 */
trait ResolvesTicketDays
{
    /**
     * Which days this order covers, or the refusal that stops it.
     *
     * Three modes, one place: a `per_day` buyer must name their days and they
     * must be days this category actually sells; a `pass` covers every day it
     * sells, whatever the client sent; a `none` category has no days at all.
     * Deriving this anywhere else would be a second reader of `day_mode`.
     *
     * @param  list<string>|null  $requested
     * @return list<string>|JsonResponse
     */
    protected function datesFor(TicketCategory $category, ?array $requested): array|JsonResponse
    {
        $selling = $category->days->map(fn ($d) => $d->event_date->toDateString())->all();

        if ($category->day_mode === 'pass') {
            return $selling;
        }

        if ($category->day_mode !== 'per_day') {
            return [];
        }

        $picked = array_values(array_unique($requested ?? []));

        if ($picked === []) {
            return ApiResponse::error(
                'Pilih tanggal kehadiran dulu.',
                ['dates' => ['Pilih minimal satu tanggal.']],
                422,
            );
        }

        // A date the category does not sell is not "sold out" — it was never on
        // offer, so it gets its own sentence rather than the quota one below.
        if ($unknown = array_diff($picked, $selling)) {
            return ApiResponse::error(
                'Ada tanggal yang tidak dijual untuk kategori ini.',
                ['dates' => ['Tanggal '.implode(', ', $unknown).' tidak tersedia.']],
                422,
            );
        }

        sort($picked);

        return $picked;
    }

    /**
     * Refuse when any one day is short of seats, naming that day.
     *
     * Per-date capacity is the venue's, so a sold-out Saturday must not be
     * buyable through Sunday's spare seats — which is exactly what reading the
     * category's own `remaining()` would allow. The date is in the message
     * because the buyer can act on it: pick another day rather than give up.
     *
     * @param  list<string>  $dates
     */
    protected function ensureSeatsAvailable(TicketCategory $category, array $dates, int $seats): ?JsonResponse
    {
        if ($dates === []) {
            $remaining = $category->remaining();

            return $remaining !== null && $remaining < $seats
                ? ApiResponse::error('Sisa tiket tidak mencukupi.', null, 422)
                : null;
        }

        foreach ($dates as $date) {
            $remaining = $category->remainingOn($date);

            if ($remaining !== null && $remaining < $seats) {
                return ApiResponse::error(
                    'Sisa tiket untuk tanggal '.$date.' tidak mencukupi.',
                    ['dates' => ['Sisa tiket tanggal '.$date.': '.$remaining.'.']],
                    422,
                );
            }
        }

        return null;
    }
}
