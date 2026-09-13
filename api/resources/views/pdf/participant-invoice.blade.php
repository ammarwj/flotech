@extends('pdf._document')
@include('pdf._participant-header')

@section('title', 'INVOICE')
@section('number', $order->invoice_number)

@section('meta')
    <tr>
        <td class="key muted">Tanggal terbit</td>
        <td>{{ $date($order->created_at) }}</td>
    </tr>
    @if ($order->payment_deadline_at)
        <tr>
            <td class="key muted">Batas bayar</td>
            <td>{{ $date($order->payment_deadline_at) }}</td>
        </tr>
    @endif
    <tr>
        <td class="key muted">Status</td>
        <td>{{ $order->paid_at ? 'Lunas' : 'Menunggu pembayaran' }}</td>
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
    @if ($order->paid_at)
        <p class="muted" style="margin-top: 24px;">
            Tagihan ini telah dibayar pada {{ $date($order->paid_at) }}.
            @if ($order->receipt_number)
                Kwitansi {{ $order->receipt_number }} diterbitkan sebagai bukti pembayaran.
            @endif
        </p>
    {{-- Manual rail: the money goes to the organizer's own account, so the
         invoice has to name it — a bill telling someone to transfer without
         saying where cannot be acted on outside the app. --}}
    {{-- A BankAccount row, not a SiteSetting: its existence *is* the account,
         so there is no hasBankAccount() to ask. --}}
    @elseif ($bank)
        <p class="muted" style="margin-top: 24px;">
            Mohon transfer tepat sejumlah di atas ke rekening berikut, lalu unggah bukti
            transfernya. Pembayaran akan dikonfirmasi setelah diverifikasi penyelenggara.
        </p>
        <table style="margin-top: 12px;">
            <tr>
                <td class="key muted">Bank</td>
                <td>{{ $bank->bank_name }}{{ $bank->bank_code ? ' ('.$bank->bank_code.')' : '' }}</td>
            </tr>
            <tr>
                <td class="key muted">No. rekening</td>
                <td>{{ $bank->account_number }}</td>
            </tr>
            <tr>
                <td class="key muted">Atas nama</td>
                <td>{{ $bank->account_holder }}</td>
            </tr>
        </table>
    @else
        <p class="muted" style="margin-top: 24px;">
            Mohon selesaikan pembayaran agar pesanan ini dikonfirmasi.
        </p>
    @endif
@endsection
