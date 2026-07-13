@extends('pdf._document')

@section('title', 'KWITANSI')
@section('number', $subscription->receipt_number)

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
    ];
@endphp

@section('meta')
    <tr>
        <td class="key muted">Tanggal bayar</td>
        <td>{{ $date($subscription->paid_at) }}</td>
    </tr>
    <tr>
        <td class="key muted">Metode bayar</td>
        <td>{{ $methods[$subscription->payment_type] ?? ($subscription->payment_type ?: '—') }}</td>
    </tr>
    <tr>
        <td class="key muted">No. invoice</td>
        <td>{{ $subscription->invoice_number ?? '—' }}</td>
    </tr>
@endsection

@section('body')
    <p class="muted" style="margin-top: 24px;">
        Telah diterima pembayaran untuk langganan tersebut di atas.
    </p>
    <div class="stamp">LUNAS</div>
@endsection
