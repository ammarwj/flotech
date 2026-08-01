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
| **Dibayar** | Rp {{ number_format((float) $order->amount, 0, ',', '.') }} |
@endcomponent

@component('mail::button', ['url' => $url])
Buat Event
@endcomponent

Paket ini menunggu satu event untuk dipakai — tidak ada masa berlaku, jadi kamu
bisa membuatnya kapan saja. Batasan paketnya berlaku pada event yang kamu buat
dengannya.
@endcomponent
