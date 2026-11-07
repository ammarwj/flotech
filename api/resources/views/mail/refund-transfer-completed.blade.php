@component('mail::message')
# Dana refund sudah kami transfer

Refund untuk **{{ $payout->recipient_name }}** sudah kami kirim ke rekening Anda. Saldo dompet Anda sebelumnya sudah dikurangi sebesar jumlah ini saat refund disetujui, jadi transfer ini menggantikannya.

@component('mail::status', ['type' => 'success', 'title' => 'Giliran Anda'])
Teruskan dananya ke peserta, lalu tandai di halaman refund beserta buktinya. Pesertanya melihat status itu di halaman pendaftarannya sendiri.
@endcomponent

@component('mail::table')
| | |
|:--- |:--- |
| **Jumlah** | Rp {{ number_format((float) $payout->amount, 0, ',', '.') }} |
| **Jenis** | {{ $payout->sourceLabel() }} |
| **Peserta** | {{ $payout->recipient_name }} |
| **Event** | {{ $payout->event?->name ?? '—' }} |
| **{{ $payout->account_type === 'ewallet' ? 'E-wallet tujuan' : 'Rekening tujuan' }}** | {{ $payout->bank_name }} · {{ $payout->account_number }} |
@if ($payout->transfer_reference)
| **Referensi transfer** | {{ $payout->transfer_reference }} |
@endif
@if ($payout->admin_note)
| **Catatan kami** | {{ $payout->admin_note }} |
@endif
@endcomponent

@component('mail::button', ['url' => $url])
Lihat & Tandai Diteruskan
@endcomponent

Dana tidak masuk juga setelah 1x24 jam? Balas email ini dengan menyertakan nama peserta di atas.
@endcomponent
