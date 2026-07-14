@component('mail::message')
# Satu langkah lagi, {{ $name }}

Terima kasih sudah bergabung dengan {{ config('brand.name') }}. Konfirmasi alamat email ini supaya akunmu aktif sepenuhnya.

@component('mail::button', ['url' => $url])
Verifikasi Email
@endcomponent

@component('mail::panel')
Tautan ini berlaku **60 menit**. Lewat dari itu, minta tautan baru dari halaman login.
@endcomponent

Kalau kamu tidak pernah mendaftar di {{ config('brand.name') }}, abaikan saja email ini — tidak ada akun yang dibuat atas namamu tanpa verifikasi ini.

@slot('subcopy')
Tombolnya tidak bisa diklik? Salin dan tempel tautan ini ke browser:
[{{ $url }}]({{ $url }})
@endslot
@endcomponent
