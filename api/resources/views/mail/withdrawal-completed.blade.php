@component('mail::message')
# Dana sudah ditransfer

Penarikan **{{ $withdrawal->reference }}** sudah kami proses dan dananya dikirim ke rekening tujuan.

@component('mail::status', ['type' => 'success', 'title' => 'Selesai'])
Dana biasanya masuk dalam hitungan menit, tapi bisa memakan waktu sampai 1 hari kerja tergantung banknya.
@endcomponent

@component('mail::table')
| | |
|:--- |:--- |
| **Jumlah diterima** | Rp {{ number_format((float) $withdrawal->amount, 0, ',', '.') }} |
| **Biaya admin** | Rp {{ number_format((float) $withdrawal->admin_fee, 0, ',', '.') }} |
| **Total didebit** | Rp {{ number_format((float) $withdrawal->total_debit, 0, ',', '.') }} |
| **Rekening** | {{ $withdrawal->bank_name }} · {{ $withdrawal->account_number }} |
| **Atas nama** | {{ $withdrawal->account_holder }} |
@if ($withdrawal->transfer_reference)
| **Referensi transfer** | {{ $withdrawal->transfer_reference }} |
@endif
@endcomponent

@component('mail::button', ['url' => $url])
Lihat Dompet
@endcomponent

Dana tidak masuk juga setelah 1 hari kerja? Balas email ini dengan menyertakan nomor penarikan di atas.
@endcomponent
