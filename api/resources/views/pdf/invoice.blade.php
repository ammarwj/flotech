@extends('pdf._document')

@section('title', 'INVOICE')
@section('number', $subscription->invoice_number)

@section('meta')
    <tr>
        <td class="key muted">Tanggal terbit</td>
        <td>{{ $date($subscription->created_at) }}</td>
    </tr>
    <tr>
        <td class="key muted">Jatuh tempo</td>
        <td>{{ $date($dueAt) }}</td>
    </tr>
    <tr>
        <td class="key muted">Status</td>
        <td>
            @switch($subscription->status)
                @case('active') Lunas @break
                @case('past_due') Menunggu pembayaran @break
                @case('cancelled') Dibatalkan @break
                @default Kedaluwarsa
            @endswitch
        </td>
    </tr>
@endsection

@section('body')
    @if ($subscription->paid_at)
        <p class="muted" style="margin-top: 24px;">
            Tagihan ini telah dibayar pada {{ $date($subscription->paid_at) }}. Kwitansi
            {{ $subscription->receipt_number }} diterbitkan sebagai bukti pembayaran.
        </p>
    @else
        <p class="muted" style="margin-top: 24px;">
            Mohon selesaikan pembayaran sebelum {{ $date($dueAt) }}. Paket akan aktif segera setelah
            pembayaran kami terima.
        </p>
    @endif
@endsection
