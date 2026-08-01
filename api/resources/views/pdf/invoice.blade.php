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
            {{-- The status set is past_due | paid | cancelled. The default is
                 deliberately the pending wording, not an "expired" one: a plan
                 order has no clock to run out, and a document that invents a
                 state the column cannot hold is worse than one that guesses
                 conservatively. --}}
            @switch($order->status)
                @case('paid') Lunas @break
                @case('cancelled') Dibatalkan @break
                @default Menunggu pembayaran
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
