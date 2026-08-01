<?php

namespace App\Exports;

use App\Models\Event;

/** Ticket orders with how many of their seats have actually been scanned in. */
class TicketBuyersExport extends EventExport
{
    public function __construct(private Event $event) {}

    public function title(): string
    {
        return 'Pembeli Tiket';
    }

    public function slug(): string
    {
        return 'pembeli-tiket';
    }

    /** @return list<string> */
    public function headings(): array
    {
        return ['Order', 'Pembeli', 'Email', 'Telepon', 'Kategori', 'Jumlah', 'Total', 'Status', 'Metode', 'Check-in', 'Dibayar'];
    }

    /** @return list<list<string|int|float|null>> */
    public function rows(): array
    {
        $orders = $this->event->ticketOrders()
            ->with(['category', 'tickets'])
            ->latest()
            ->get();

        return $orders->map(fn ($o) => [
            $o->midtrans_order_id ?? $o->id,
            $o->buyer_name,
            $o->buyer_email,
            $o->buyer_phone ?? '—',
            $o->category?->name ?? '—',
            (int) $o->quantity,
            (float) $o->total_price,
            $o->status,
            $o->payment_method,
            $o->tickets->where('is_used', true)->count().'/'.$o->quantity,
            $o->paid_at?->toDateTimeString() ?? '—',
        ])->all();
    }
}
