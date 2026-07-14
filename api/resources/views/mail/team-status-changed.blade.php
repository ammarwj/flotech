@component('mail::message')
# {{ $team->name }}

Halo {{ $team->contact_name }}, ada kabar dari penyelenggara {{ $event->name }}.

@component('mail::status', ['type' => $type, 'title' => $title])
{{ $body }}
@endcomponent

@component('mail::table')
| | |
|:--- |:--- |
| **Event** | {{ $event->name }} |
| **Tanggal** | {{ $event->start_date?->translatedFormat('d F Y') }} |
| **Lokasi** | {{ $event->location_name ?: '—' }} |
@endcomponent

@component('mail::button', ['url' => $url])
Buka Halaman Tim
@endcomponent
@endcomponent
