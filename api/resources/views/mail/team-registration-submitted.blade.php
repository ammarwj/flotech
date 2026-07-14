@component('mail::message')
# Pendaftaran tim diterima

Halo {{ $team->contact_name }}, pendaftaran **{{ $team->name }}** sudah masuk ke {{ $event->name }}.

@component('mail::status', ['type' => 'info', 'title' => 'Menunggu verifikasi'])
Penyelenggara akan memeriksa data tim kamu. Kami kabari lagi lewat email begitu ada keputusan.
@endcomponent

@component('mail::table')
| | |
|:--- |:--- |
| **Event** | {{ $event->name }} |
| **Tanggal** | {{ $event->start_date?->translatedFormat('d F Y') }} |
| **Tim** | {{ $team->name }} |
| **Pemain terdaftar** | {{ $team->players()->count() }} orang |
@endcomponent

@component('mail::button', ['url' => $url])
Lihat Status Tim
@endcomponent

Masih bisa melengkapi roster dan dokumen dari halaman tim selama pendaftaran belum diverifikasi.
@endcomponent
