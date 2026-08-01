<?php

namespace App\Exports;

use App\Models\Event;

/**
 * Every entrant of an event, one row per team, with its roster size and payment
 * state — the list an organizer actually prints for the technical meeting.
 */
class RegistrationsExport extends EventExport
{
    public function __construct(private Event $event) {}

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
        return ['Kategori', 'Nama', 'Status', 'Kontak', 'Telepon', 'Pemain', 'Ofisial', 'Pembayaran', 'Biaya', 'Terdaftar'];
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
        ])->all();
    }
}
