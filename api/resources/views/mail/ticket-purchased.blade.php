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
| **Total** | Rp {{ number_format((float) $order->total_price, 0, ',', '.') }} |
@endcomponent

@component('mail::button', ['url' => $ticketUrl])
Lihat E-Tiket
@endcomponent

Buka halaman e-tiket untuk melihat QR code setiap tiket. Tunjukkan QR tersebut ke petugas saat check-in — satu QR hanya bisa dipakai satu kali.

Simpan email ini sebagai bukti pembelian.

Terima kasih,<br>
{{ config('app.name') }}
@endcomponent
