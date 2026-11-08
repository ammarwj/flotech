@component('mail::message')
@if ($cards->count() === 1)
# ID card kamu sudah siap 🪪

Halo **{{ $cards->first()->recipient_name }}**, ID card kamu untuk **{{ $event?->name }}** terlampir di email ini. Cetak atau simpan di ponsel — petugas di lokasi bisa memindai QR-nya.

@component('mail::table')
| | |
|:--- |:--- |
| **Nama** | {{ $cards->first()->recipient_name }} |
@if ($cards->first()->role_label)
| **Sebagai** | {{ $cards->first()->role_label }} |
@endif
@if ($cards->first()->team_name)
| **Tim** | {{ $cards->first()->team_name }} |
@endif
| **Event** | {{ $event?->name }} |
| **Tanggal** | {{ $event?->start_date?->translatedFormat('d F Y') }} |
| **Nomor kartu** | {{ $cards->first()->card_number }} |
@endcomponent

@component('mail::button', ['url' => $verifyUrl])
Verifikasi Kartu
@endcomponent

QR di kartu mengarah ke halaman verifikasi di atas — siapa pun bisa memakainya untuk memastikan kartu ini asli.
@else
# ID card tim kamu sudah siap 🪪

Halo, **{{ $cards->count() }} ID card** untuk **{{ $event?->name }}** terlampir di email ini — satu berkas PNG per orang. Cetak atau teruskan ke masing-masing; petugas di lokasi bisa memindai QR-nya.

@component('mail::table')
| Nama | Sebagai | Nomor kartu |
|:--- |:--- |:--- |
@foreach ($cards as $card)
| {{ $card->recipient_name }} | {{ $card->role_label ?: '—' }} | {{ $card->card_number }} |
@endforeach
@endcomponent

Setiap kartu punya nomornya sendiri dan QR yang mengarah ke halaman verifikasinya, jadi keasliannya bisa diperiksa satu per satu tanpa menghubungi penyelenggara.
@endif

Salam,<br>
{{ config('app.name') }}
@endcomponent
