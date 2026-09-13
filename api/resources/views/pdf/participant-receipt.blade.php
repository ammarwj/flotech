@extends('pdf._document')
@include('pdf._participant-header')

@section('title', 'KWITANSI')
@section('number', $order->receipt_number)

@section('meta')
    <tr>
        <td class="key muted">Tanggal bayar</td>
        <td>{{ $date($order->paid_at) }}</td>
    </tr>
    <tr>
        <td class="key muted">Metode bayar</td>
        <td>{{ $methodLabel }}</td>
    </tr>
    <tr>
        <td class="key muted">No. invoice</td>
        <td>{{ $order->invoice_number ?? '—' }}</td>
    </tr>
@endsection

@section('items')
    <tr>
        <td>
            <div style="font-weight: bold;">{{ $itemTitle }}</div>
            @if ($itemNote)
                <div class="muted">{{ $itemNote }}</div>
            @endif
        </td>
        <td class="muted">{{ $order->event?->name ?? '—' }}</td>
        <td class="right">{{ $money($itemAmount) }}</td>
    </tr>
@endsection

@section('body')
    <p class="muted" style="margin-top: 24px;">
        Telah diterima pembayaran untuk transaksi tersebut di atas.
    </p>
    <div class="stamp">LUNAS</div>
@endsection
