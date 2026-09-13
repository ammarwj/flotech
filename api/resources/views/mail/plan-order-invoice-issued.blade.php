@component('mail::message')
# Tagihan {{ $order->invoice_number }}

Halo {{ $order->organization->name }}, tagihan untuk paket **{{ $order->plan->name }}** sudah terbit. PDF-nya terlampir di email ini.

@component('mail::status', ['type' => 'warning', 'title' => 'Belum dibayar'])
Selesaikan pembayaran sebelum **{{ $dueAt->timezone(config('wallet.timezone'))->translatedFormat('d F Y') }}** agar paketmu langsung bisa dipakai.
@endcomponent

@component('mail::table')
| | |
|:--- |:--- |
| **Nomor** | {{ $order->invoice_number }} |
| **Paket** | Rp {{ number_format((float) $order->amount, 0, ',', '.') }} |
| **Berlaku untuk** | 1 event |
{{-- Same rows, same order, same conditions as the PDF: an email that totalled
     differently from its own attachment is worse than one that omits the split. --}}
@if ((float) $order->service_fee > 0)
| **Biaya layanan** | Rp {{ number_format((float) $order->service_fee, 0, ',', '.') }} |
@endif
@if ((float) $order->gateway_fee > 0)
| **Biaya payment gateway** | Rp {{ number_format((float) $order->gateway_fee - (float) $order->gateway_tax, 0, ',', '.') }} |
@endif
@if ((float) $order->gateway_tax > 0)
| **PPN** | Rp {{ number_format((float) $order->gateway_tax, 0, ',', '.') }} |
@endif
| **Total** | Rp {{ number_format($order->gross_amount, 0, ',', '.') }} |
@endcomponent

@component('mail::button', ['url' => $url])
Bayar Sekarang
@endcomponent

Sudah membayar? Abaikan email ini — kwitansinya menyusul otomatis.
@endcomponent
