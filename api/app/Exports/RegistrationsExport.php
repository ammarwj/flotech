<?php

namespace App\Exports;

use App\Models\Event;
use App\Support\RegistrationForm;

/**
 * Every entrant of an event, one row per team, with its roster size and payment
 * state — the list an organizer actually prints for the technical meeting.
 *
 * The team fields the organizer added to their registration form become extra
 * columns at the end, labelled the way they were labelled on the form. Player
 * fields are not here: this sheet is one row per team, and a squad of fifteen
 * has fifteen answers to each. They are read from the registrations page.
 */
class RegistrationsExport extends EventExport
{
    public function __construct(private Event $event) {}

    /** @return list<array<string, mixed>> */
    private function fields(): array
    {
        return RegistrationForm::forEvent($this->event)->teamFields;
    }

    public function title(): string
    {
        return 'Peserta';
    }

    public function slug(): string
    {
        return 'peserta';
    }

    /** @return list<string> */
    public function headings(): array
    {
        return [
            'Kategori', 'Nama', 'Status', 'Kontak', 'Telepon', 'Pemain', 'Ofisial', 'Pembayaran', 'Biaya', 'Terdaftar',
            // Appended, never interleaved: an organizer who has been diffing
            // this sheet between exports should not find the columns moved
            // because they added a field.
            ...array_column($this->fields(), 'label'),
        ];
    }

    /** @return list<list<string|int|float|null>> */
    public function rows(): array
    {
        $teams = $this->event->teams()
            ->with('category')
            ->withCount(['players', 'officials'])
            ->orderBy('category_id')
            ->orderBy('name')
            ->get();

        $fields = $this->fields();

        return $teams->map(fn ($t) => [
            $t->category?->name ?? '—',
            $t->name,
            $t->status,
            $t->contact_name ?? '—',
            $t->contact_phone ?? '—',
            (int) $t->players_count,
            (int) $t->officials_count,
            $t->payment_status ?? '—',
            (float) ($t->payment_amount ?? 0),
            $t->registered_at?->toDateTimeString() ?? '—',
            // Read by key off the schema, not by iterating the stored answers:
            // a team that skipped an optional field still needs its cell, or
            // every column after it shifts up a place on that row.
            ...array_map(fn ($f) => $t->custom_fields[$f['key']] ?? '—', $fields),
        ])->all();
    }
}
