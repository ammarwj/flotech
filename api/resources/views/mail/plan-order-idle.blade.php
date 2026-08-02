@component('mail::message')
# Paket {{ $order->plan?->name }} kamu belum dipakai

Halo {{ $order->organization->name }}. Paket yang kamu bayar
{{ $idleDays }} hari lalu masih menunggu satu event.

@component('mail::table')
| | |
|:--- |:--- |
| **Paket** | {{ $order->plan?->name }} |
| **No. Invoice** | {{ $order->invoice_number ?? '—' }} |
| **Dibayar** | Rp {{ number_format((float) $order->amount, 0, ',', '.') }} |
@endcomponent

@component('mail::button', ['url' => $url])
Buat Event Sekarang
@endcomponent

Tidak ada yang hangus — paket ini **tidak punya masa berlaku** dan akan tetap
menunggu sampai kamu pakai. Email ini cuma pengingat supaya ia tidak terlupakan.

Kalau ternyata eventmu butuh paket yang lebih besar, kamu bisa menaikkannya dari
halaman Pembelian Paket dan hanya membayar selisihnya.
@endcomponent
