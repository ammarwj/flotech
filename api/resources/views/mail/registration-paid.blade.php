@component('mail::message')
# Pembayaran diterima

Halo {{ $team->contact_name }}, biaya pendaftaran **{{ $team->name }}** untuk {{ $event->name }} sudah lunas.

@component('mail::status', ['type' => 'success', 'title' => 'Lunas'])
Slot tim kamu aman. Tidak ada lagi yang perlu dibayar untuk pendaftaran ini.
@endcomponent

@component('mail::table')
| | |
|:--- |:--- |
| **Event** | {{ $event->name }} |
| **Tim** | {{ $team->name }} |
| **Jumlah** | Rp {{ number_format((float) $team->payment_amount, 0, ',', '.') }} |
| **Dibayar** | {{ $team->paid_at?->timezone(config('wallet.timezone'))->translatedFormat('d F Y, H:i') }} WIB |
@endcomponent

@component('mail::button', ['url' => $url])
Lihat Tim Kamu
@endcomponent

Simpan email ini sebagai bukti pembayaran.
@endcomponent
