@component('mail::message')
# Paket {{ $order->plan->name }} siap dipakai

Terima kasih, {{ $order->organization->name }}. Pembayaranmu sudah kami terima.

@component('mail::status', ['type' => 'success', 'title' => 'Lunas'])
Invoice **{{ $order->invoice_number }}** dan kwitansi **{{ $order->receipt_number }}** terlampir sebagai PDF di email ini.
@endcomponent

@component('mail::table')
| | |
|:--- |:--- |
| **Paket** | {{ $order->plan->name }} |
| **No. Invoice** | {{ $order->invoice_number }} |
| **Berlaku untuk** | 1 event |
| **Harga paket** | Rp {{ number_format((float) $order->amount, 0, ',', '.') }} |
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
| **Dibayar** | Rp {{ number_format($order->gross_amount, 0, ',', '.') }} |
@endcomponent

@component('mail::button', ['url' => $url])
Buat Event
@endcomponent

Paket ini menunggu satu event untuk dipakai — tidak ada masa berlaku, jadi kamu
bisa membuatnya kapan saja. Batasan paketnya berlaku pada event yang kamu buat
dengannya.
@endcomponent
