@component('mail::message')
# Penarikan dana ditolak

Permintaan penarikan **{{ $withdrawal->reference }}** tidak bisa kami proses.

@component('mail::status', ['type' => 'error', 'title' => 'Ditolak'])
{{ $reason }}
@endcomponent

Dananya **sudah dikembalikan ke saldo tersedia** — tidak ada rupiah yang hilang. Perbaiki penyebab di atas, lalu ajukan penarikan baru.

@component('mail::table')
| | |
|:--- |:--- |
| **Jumlah** | Rp {{ number_format((float) $withdrawal->amount, 0, ',', '.') }} |
| **Rekening tujuan** | {{ $withdrawal->bank_name }} · {{ $withdrawal->account_number }} |
| **Atas nama** | {{ $withdrawal->account_holder }} |
@endcomponent

@component('mail::button', ['url' => $url, 'color' => 'error'])
Buka Dompet
@endcomponent
@endcomponent
