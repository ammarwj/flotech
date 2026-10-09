@component('mail::message')
# Pembayaran berhasil 🎟️

Halo **{{ $order->buyer_name }}**, tiketmu untuk **{{ $event->name }}** sudah aktif.

@php
    // Built here rather than with @if inside the table: a Blade directive on
    // its own line breaks the run of `|` rows markdown needs to see a table at
    // all, and the fee rows are conditional. Same rows, same order and same
    // conditions as the PDF — a mail that totals differently from its own
    // attachment is worse than one that omits the split.
    $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
    $dates = $order->event_dates ?? [];
    $tickets = count($dates) > 0 ? max(1, (int) $order->seats) * count($dates) : (int) $order->quantity;

    $rows = [
        'Event' => $event->name,
        // The days this order actually bought, not the event's own range: a
        // buyer who picked only Sunday must not be told the event starts
        // Friday. Falls back to the start date for a dateless order, which is
        // every order placed before per-day ticketing existed.
        'Tanggal' => $dates === []
            ? $event->start_date?->translatedFormat('d F Y')
            : collect($dates)
                ->map(fn ($d) => \Illuminate\Support\Carbon::parse($d)->translatedFormat('d F Y'))
                ->implode(' · '),
        'Lokasi' => $event->location_name ?: '—',
        'Kategori' => $category?->name ?? '—',
        // Seats and QRs, because for a per-day order they differ and the count
        // of QRs is what the holder has to carry.
        'Jumlah' => $dates === []
            ? $order->quantity.' tiket'
            : $order->seats.' orang · '.$tickets.' tiket',
        'Harga tiket' => $rp($order->total_price),
    ];

    // Per ticket, not per order: three tickets carry three fees, so the value
    // spells the arithmetic out rather than leaving the buyer to divide a total
    // they have no rate to check against. It goes in the *value* cell, not the
    // label — the labels are what test_mail_renders_every_row_inside_one_table
    // matches on to prove each row is still a table cell.
    if ((float) $order->service_fee > 0) {
        // Paid units, which is what the fee was charged per — `quantity` is
        // exactly that number, including for a per-day order.
        $units = max(1, (int) $order->quantity);
        $rows['Biaya layanan'] = $units > 1
            ? $rp($order->service_fee).' ('.$rp((float) $order->service_fee / $units).' × '.$units.' tiket)'
            : $rp($order->service_fee);
    }

    // Rows settled before gateway_tax existed report 0 and keep the single
    // combined label, exactly as they rendered before.
    if ((float) $order->gateway_fee > 0) {
        $taxed = (float) $order->gateway_tax > 0;
        $rows[$taxed ? 'Biaya payment gateway' : 'Biaya pembayaran']
            = $rp((float) $order->gateway_fee - (float) $order->gateway_tax);

        if ($taxed) {
            $rows['PPN'] = $rp($order->gateway_tax);
        }
    }

    $rows['Total dibayar'] = $rp($order->gross_amount);
@endphp

@component('mail::table')
| | |
|:--- |:--- |
@foreach ($rows as $label => $value)
| **{{ $label }}** | {{ $value }} |
@endforeach
@endcomponent

@component('mail::button', ['url' => $ticketUrl])
Lihat E-Tiket
@endcomponent

Buka halaman e-tiket untuk melihat QR code setiap tiket. Tunjukkan QR tersebut ke petugas saat check-in — satu QR hanya bisa dipakai satu kali.

Simpan email ini sebagai bukti pembelian.

Terima kasih,<br>
{{ config('app.name') }}
@endcomponent
