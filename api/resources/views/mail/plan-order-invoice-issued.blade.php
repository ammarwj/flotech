@component('mail::message')
# Tagihan {{ $order->invoice_number }}

Halo {{ $order->organization->name }}, tagihan untuk paket **{{ $order->plan->name }}** sudah terbit. PDF-nya terlampir di email ini.

@component('mail::status', ['type' => 'warning', 'title' => 'Belum dibayar'])
Selesaikan pembayaran sebelum **{{ $dueAt->timezone(config('wallet.timezone'))->translatedFormat('d F Y') }}** agar paketmu langsung bisa dipakai.
@endcomponent

@component('mail::table')
| | |
|:--- |:--- |
| **Nomor** | {{ $order->invoice_number }} |
| **Paket** | {{ $order->plan->name }} |
| **Berlaku untuk** | 1 event |
| **Total** | Rp {{ number_format((float) $order->amount, 0, ',', '.') }} |
@endcomponent

@component('mail::button', ['url' => $url])
Bayar Sekarang
@endcomponent

Sudah membayar? Abaikan email ini — kwitansinya menyusul otomatis.
@endcomponent
