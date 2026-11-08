@component('mail::message')
# e-BIB kamu sudah siap 🏃

Halo **{{ $entry->full_name }}**, nomor dada kamu untuk **{{ $event?->name }}** terlampir di email ini. Cetak di kertas A5 dan pasang di depan, atau tunjukkan berkasnya di ponsel saat pengambilan race pack.

@component('mail::table')
| | |
|:--- |:--- |
| **Nama** | {{ $entry->full_name }} |
@if ($entry->bib_number)
| **Nomor BIB** | {{ $entry->bib_number }} |
@endif
@if ($entry->category?->name)
| **Kategori** | {{ $entry->category->name }} |
@endif
| **Event** | {{ $event?->name }} |
| **Tanggal** | {{ $event?->start_date?->translatedFormat('d F Y') }} |
@endcomponent

QR di e-BIB ini **adalah tiket race pack kamu** — petugas memindainya untuk mencatat kehadiran, jadi jangan dibagikan ke orang lain dan cukup dipindai sekali.

Salam,<br>
{{ config('app.name') }}
@endcomponent
