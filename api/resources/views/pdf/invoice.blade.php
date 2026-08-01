@extends('pdf._document')

@section('title', 'INVOICE')
@section('number', $order->invoice_number)

@section('meta')
    <tr>
        <td class="key muted">Tanggal terbit</td>
        <td>{{ $date($order->created_at) }}</td>
    </tr>
    <tr>
        <td class="key muted">Jatuh tempo</td>
        <td>{{ $date($dueAt) }}</td>
    </tr>
    <tr>
        <td class="key muted">Status</td>
        <td>
            @switch($order->status)
                @case('active') Lunas @break
                @case('past_due') Menunggu pembayaran @break
                @case('cancelled') Dibatalkan @break
                @default Kedaluwarsa
            @endswitch
        </td>
    </tr>
@endsection

@section('body')
    @if ($order->paid_at)
        <p class="muted" style="margin-top: 24px;">
            Tagihan ini telah dibayar pada {{ $date($order->paid_at) }}. Kwitansi
            {{ $order->receipt_number }} diterbitkan sebagai bukti pembayaran.
        </p>
    @elseif ($bank && $bank->hasBankAccount())
        <p class="muted" style="margin-top: 24px;">
            Mohon transfer tepat sejumlah di atas ke rekening berikut sebelum
            {{ $date($dueAt) }}, lalu unggah bukti transfernya di dashboard. Paket akan aktif
            setelah pembayaran kami verifikasi.
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
            Mohon selesaikan pembayaran sebelum {{ $date($dueAt) }}. Paket akan aktif segera setelah
            pembayaran kami terima.
        </p>
    @endif
@endsection
