@component('mail::message')
# Pembayaran berhasil 🎟️

Halo **{{ $order->buyer_name }}**, tiketmu untuk **{{ $event->name }}** sudah aktif.

@component('mail::table')
| | |
|:--- |:--- |
| **Event** | {{ $event->name }} |
| **Tanggal** | {{ $event->start_date?->translatedFormat('d F Y') }} |
| **Lokasi** | {{ $event->location_name ?: '—' }} |
| **Kategori** | {{ $category?->name ?? '—' }} |
| **Jumlah** | {{ $order->quantity }} tiket |
| **Harga tiket** | Rp {{ number_format((float) $order->total_price, 0, ',', '.') }} |
{{-- One combined fee line, unlike the plan-order emails: ticket_orders stores
     no tax split, so breaking it out here would be a number nothing backs. --}}
@if ((float) $order->gateway_fee + (float) $order->service_fee > 0)
| **Biaya pembayaran** | Rp {{ number_format((float) $order->gateway_fee + (float) $order->service_fee, 0, ',', '.') }} |
@endif
| **Total dibayar** | Rp {{ number_format($order->gross_amount, 0, ',', '.') }} |
@endcomponent

@component('mail::button', ['url' => $ticketUrl])
Lihat E-Tiket
@endcomponent

Buka halaman e-tiket untuk melihat QR code setiap tiket. Tunjukkan QR tersebut ke petugas saat check-in — satu QR hanya bisa dipakai satu kali.

Simpan email ini sebagai bukti pembelian.

Terima kasih,<br>
{{ config('app.name') }}
@endcomponent
