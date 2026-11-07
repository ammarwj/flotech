@component('mail::message')
# Refund menunggu transfer

Sebuah refund sudah disetujui, dan saldo penyelenggara **sudah dikurangi**. Dananya ada di akun kami, jadi transfer baliknya menunggu diproses.

@component('mail::status', ['type' => 'warning', 'title' => 'Perlu transfer manual'])
Transfer ke rekening penyelenggara, lalu unggah bukti di antrean refund. Penyelenggara yang meneruskannya ke peserta.
@endcomponent

@component('mail::table')
| | |
|:--- |:--- |
| **Jumlah** | Rp {{ number_format((float) $payout->amount, 0, ',', '.') }} |
| **Jenis** | {{ $payout->sourceLabel() }} |
| **Peserta** | {{ $payout->recipient_name }} |
| **Penyelenggara** | {{ $payout->organization?->name ?? '—' }} |
| **Event** | {{ $payout->event?->name ?? '—' }} |
@if ($payout->account_number)
| **{{ $payout->account_type === 'ewallet' ? 'E-wallet tujuan' : 'Rekening tujuan' }}** | {{ $payout->bank_name }} · {{ $payout->account_number }} |
| **Atas nama** | {{ $payout->account_holder }} |
@else
| **Rekening tujuan** | Belum diisi penyelenggara |
@endif
@if ($payout->reason)
| **Alasan refund** | {{ $payout->reason }} |
@endif
@endcomponent

@if (! $payout->account_number)
@component('mail::status', ['type' => 'error', 'title' => 'Rekening tujuan kosong'])
Penyelenggara ini belum punya rekening utama, jadi transfernya belum bisa dijalankan. Hubungi mereka untuk mengisinya lebih dulu.
@endcomponent
@endif

@component('mail::button', ['url' => $url])
Buka Antrean Refund
@endcomponent
@endcomponent
