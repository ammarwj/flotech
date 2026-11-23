@component('mail::message')
# Ada tim baru mendaftar

**{{ $team->name }}** baru saja mendaftar ke {{ $event->name }} dan menunggu verifikasi kamu.

@component('mail::table')
| | |
|:--- |:--- |
| **{{ $form->label('team_name') }}** | {{ $team->name }} |
| **Kota** | {{ $team->city ?: '—' }} |
| **{{ $form->label('contact_name') }}** | {{ $team->contact_name }} · {{ $team->contact_phone }} |
| **Pemain** | {{ $team->players()->count() }} orang |
| **Event** | {{ $event->name }} |
@endcomponent

@component('mail::button', ['url' => $url])
Tinjau Pendaftaran
@endcomponent

Tim yang belum disetujui tidak ikut diundi ke jadwal.
@endcomponent
