@component('mail::message')
# {{ $title }}

Halo {{ $personnel->full_name }},

{{ $body }}

@component('mail::table')
| | |
|:--- |:--- |
| **Event** | {{ $event->name }} |
| **Tanggal** | {{ $event->start_date?->translatedFormat('d F Y') ?: '—' }} |
| **Lokasi** | {{ $event->location_name ?: '—' }} |
| **Tugas** | {{ $role }} |
@endcomponent

@if ($withPassword)
@component('mail::panel')
**Email:** {{ $email }}
**Password sementara:** {{ $password }}
@endcomponent

Password di atas hanya untuk masuk pertama kali. Begitu kamu masuk, sistem akan
langsung meminta kamu menggantinya — sampai diganti, tidak ada halaman lain yang
bisa dibuka. Jangan pakai ulang password ini di tempat lain.
@else
Masuk memakai email **{{ $email }}** dengan password yang sudah kamu pakai
selama ini. Kalau lupa, pakai tautan "Lupa password?" di halaman masuk.
@endif

@component('mail::button', ['url' => $url])
Buka Area Petugas
@endcomponent
@endcomponent
