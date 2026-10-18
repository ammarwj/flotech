<?php

namespace App\Exports;

use App\Models\Event;
use App\Support\RegistrationForm;

/**
 * Every runner of a fun run, one row each — the start list panitia print for
 * the registration desk.
 *
 * Twin of RegistrationsExport, including the two rules that matter there and
 * apply unchanged here: the organizer's own form fields become extra columns
 * appended at the end (never interleaved, or a sheet somebody has been diffing
 * between exports finds its columns moved), and each one is read by key off the
 * schema rather than by iterating the stored answers, so a runner who skipped
 * an optional field still gets a cell instead of shifting every column after it
 * one place up that row.
 *
 * The period column is here for the same reason it is on the receipt: the same
 * category costs three different amounts over a sale, and a price with no window
 * beside it cannot be checked against anything.
 */
class RaceEntriesExport extends EventExport
{
    public function __construct(private Event $event) {}

    /** @return list<array<string, mixed>> */
    private function fields(): array
    {
        // The team-level fields, which for a race are the runner's own: there
        // is no roster, so `player_fields` never applies. Same schema, read for
        // the one section that means anything here.
        return RegistrationForm::forEvent($this->event)->teamFields;
    }

    public function title(): string
    {
        return 'Peserta Lomba';
    }

    public function slug(): string
    {
        return 'peserta-lomba';
    }

    /** @return list<string> */
    public function headings(): array
    {
        return [
            // `headings()` and `rows()` are computed separately, so a column
            // added here must go in at the SAME position below — once the two
            // disagree the sheet prints every later value one column to the
            // left, which is the trap the standings export's `Adj` column
            // documents at length.
            'Kategori', 'Nama', 'Email', 'Telepon', 'Gender', 'Tgl Lahir',
            'Kontak Darurat', 'Telepon Darurat', 'Hubungan',
            'Periode', 'Biaya', 'Status', 'Metode', 'Terdaftar',
            ...array_column($this->fields(), 'label'),
        ];
    }

    /** @return list<list<string|int|float|null>> */
    public function rows(): array
    {
        $entries = $this->event->raceEntries()
            ->with('category')
            ->orderBy('race_category_id')
            ->orderBy('full_name')
            ->get();

        $fields = $this->fields();

        return $entries->map(fn ($e) => [
            $e->category?->name ?? '—',
            $e->full_name,
            $e->email,
            $e->phone ?? '—',
            $e->gender ?? '—',
            $e->birth_date?->toDateString() ?? '—',
            $e->emergency_contact_name ?? '—',
            $e->emergency_contact_phone ?? '—',
            $e->emergency_contact_relation ?? '—',
            // The snapshot, not the relation: the period may since have been
            // repriced or deleted, and this sheet has to keep saying what was
            // actually charged.
            $e->period_name,
            (float) $e->price,
            $e->status,
            // The raw column would print "gateway" into a sheet an organizer
            // hands to someone else; the labels live on HasManualPayment, which
            // is the one place that knows what the rail values mean.
            $e->methodLabel(),
            $e->created_at?->toDateTimeString() ?? '—',
            ...array_map(fn ($f) => $e->custom_fields[$f['key']] ?? '—', $fields),
        ])->all();
    }
}
