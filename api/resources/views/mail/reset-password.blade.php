@component('mail::message')
# Atur ulang password

Halo {{ $name }}, kami menerima permintaan untuk mengatur ulang password akunmu.

@component('mail::button', ['url' => $url])
Buat Password Baru
@endcomponent

@component('mail::panel')
Tautan ini berlaku **{{ $minutes }} menit** dan hanya bisa dipakai sekali.
@endcomponent

**Tidak merasa meminta ini?** Abaikan email ini — password kamu tidak berubah selama tautan di atas tidak diklik.

@slot('subcopy')
Tombolnya tidak bisa diklik? Salin dan tempel tautan ini ke browser:
[{{ $url }}]({{ $url }})
@endslot
@endcomponent
