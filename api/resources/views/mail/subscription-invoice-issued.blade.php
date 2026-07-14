@component('mail::message')
# Tagihan {{ $subscription->invoice_number }}

Halo {{ $subscription->organization->name }}, tagihan untuk paket **{{ $subscription->plan->name }}** sudah terbit. PDF-nya terlampir di email ini.

@component('mail::status', ['type' => 'warning', 'title' => 'Belum dibayar'])
Selesaikan pembayaran sebelum **{{ $dueAt->timezone(config('wallet.timezone'))->translatedFormat('d F Y') }}** agar paketmu langsung aktif.
@endcomponent

@component('mail::table')
| | |
|:--- |:--- |
| **Nomor** | {{ $subscription->invoice_number }} |
| **Paket** | {{ $subscription->plan->name }} |
| **Siklus** | {{ $subscription->billing_cycle === 'yearly' ? 'Tahunan' : 'Bulanan' }} |
| **Total** | Rp {{ number_format((float) $subscription->amount, 0, ',', '.') }} |
@endcomponent

@component('mail::button', ['url' => $url])
Bayar Sekarang
@endcomponent

Sudah membayar? Abaikan email ini — kwitansinya menyusul otomatis.
@endcomponent
