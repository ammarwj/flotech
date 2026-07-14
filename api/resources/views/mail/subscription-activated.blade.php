@component('mail::message')
# Paket {{ $subscription->plan->name }} aktif

Terima kasih, {{ $subscription->organization->name }}. Pembayaranmu sudah kami terima dan paketnya langsung berlaku.

@component('mail::status', ['type' => 'success', 'title' => 'Lunas'])
Kwitansi **{{ $subscription->receipt_number }}** terlampir sebagai PDF di email ini.
@endcomponent

@component('mail::table')
| | |
|:--- |:--- |
| **Paket** | {{ $subscription->plan->name }} |
| **Siklus** | {{ $subscription->billing_cycle === 'yearly' ? 'Tahunan' : 'Bulanan' }} |
| **Dibayar** | Rp {{ number_format((float) $subscription->amount, 0, ',', '.') }} |
| **Berlaku sampai** | {{ $subscription->expires_at?->timezone(config('wallet.timezone'))->translatedFormat('d F Y') ?? '—' }} |
@endcomponent

@component('mail::button', ['url' => $url])
Buka Dashboard
@endcomponent

Semua batasan paket barumu berlaku mulai sekarang — buat event, undang tim, terbitkan jadwal.
@endcomponent
