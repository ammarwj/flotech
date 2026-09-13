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
    $rows = [
        'Event' => $event->name,
        'Tanggal' => $event->start_date?->translatedFormat('d F Y'),
        'Lokasi' => $event->location_name ?: '—',
        'Kategori' => $category?->name ?? '—',
        'Jumlah' => $order->quantity.' tiket',
        'Harga tiket' => $rp($order->total_price),
    ];

    if ((float) $order->service_fee > 0) {
        $rows['Biaya layanan'] = $rp($order->service_fee);
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
