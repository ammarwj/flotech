@extends('pdf._document')

@section('title', 'KWITANSI')
@section('number', $order->receipt_number)

@php
    $methods = [
        'bank_transfer' => 'Transfer bank',
        'echannel' => 'Mandiri bill payment',
        'permata' => 'Permata virtual account',
        'credit_card' => 'Kartu kredit',
        'gopay' => 'GoPay',
        'shopeepay' => 'ShopeePay',
        'qris' => 'QRIS',
        'cstore' => 'Gerai retail',
        // Not a Midtrans payment type: written by EventPlanOrderService when a
        // super admin accepts a transfer receipt while the gateway is off.
        'manual_transfer' => 'Transfer manual (diverifikasi admin)',
    ];
@endphp

@section('meta')
    <tr>
        <td class="key muted">Tanggal bayar</td>
        <td>{{ $date($order->paid_at) }}</td>
    </tr>
    <tr>
        <td class="key muted">Metode bayar</td>
        <td>{{ $methods[$order->payment_type] ?? ($order->payment_type ?: '—') }}</td>
    </tr>
    <tr>
        <td class="key muted">No. invoice</td>
        <td>{{ $order->invoice_number ?? '—' }}</td>
    </tr>
@endsection

@section('body')
    <p class="muted" style="margin-top: 24px;">
        Telah diterima pembayaran untuk langganan tersebut di atas.
    </p>
    <div class="stamp">LUNAS</div>
@endsection
