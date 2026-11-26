@component('mail::message')
# Undangan jadi operator event

Halo,

{{ $invitedBy->full_name ?? 'Penyelenggara' }} dari **{{ $organization->name }}** mengundang
kamu untuk membantu mengelola event **{{ $event->name }}** di flo-event.

@component('mail::table')
| | |
|:--- |:--- |
| **Event** | {{ $event->name }} |
| **Penyelenggara** | {{ $organization->name }} |
| **Akses yang diberikan** | {{ implode(', ', $permissionLabels) }} |
@endcomponent

Klik tombol di bawah untuk melihat undangan ini. Kalau kamu belum punya akun,
kamu akan diminta mendaftar dulu dengan email ini sebelum bisa menerimanya.

@component('mail::button', ['url' => $url])
Lihat Undangan
@endcomponent

Tautan ini berlaku selama 7 hari sejak dikirim. Kalau sudah kedaluwarsa, minta
penyelenggara mengirim ulang undangannya.
@endcomponent
