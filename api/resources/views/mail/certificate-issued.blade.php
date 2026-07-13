@component('mail::message')
# Sertifikat kamu sudah terbit 🏆

Halo **{{ $certificate->recipient_name }}**, sertifikatmu untuk **{{ $event->name }}** sudah terbit dan terlampir di email ini.

@component('mail::table')
| | |
|:--- |:--- |
| **Penghargaan** | {{ $certificate->award_title }} |
| **Event** | {{ $event->name }} |
| **Tanggal** | {{ $event->start_date?->translatedFormat('d F Y') }} |
@if ($certificate->team_name)
| **Tim** | {{ $certificate->team_name }} |
@endif
| **Nomor** | {{ $certificate->certificate_number }} |
@endcomponent

@component('mail::button', ['url' => $verifyUrl])
Verifikasi Sertifikat
@endcomponent

Setiap sertifikat punya nomor unik dan QR yang mengarah ke halaman verifikasi di atas — siapa pun bisa memakainya untuk memastikan sertifikat ini asli.

Selamat,<br>
{{ config('app.name') }}
@endcomponent
