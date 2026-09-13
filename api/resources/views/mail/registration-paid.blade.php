@component('mail::message')
# Pembayaran diterima

Halo {{ $team->contact_name }}, biaya pendaftaran **{{ $team->name }}** untuk {{ $event->name }} sudah lunas.

@component('mail::status', ['type' => 'success', 'title' => 'Lunas'])
Slot tim kamu aman. Tidak ada lagi yang perlu dibayar untuk pendaftaran ini.
@endcomponent

@php
    // Built here rather than with @if inside the table: a Blade directive on
    // its own line breaks the run of `|` rows markdown needs to see a table at
    // all, and the fee rows are conditional. Same rows, same order and same
    // conditions as the PDF — a mail that totals differently from its own
    // attachment is worse than one that omits the split.
    $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
    $rows = [
        'Event' => $event->name,
        'Tim' => $team->name,
        'Biaya pendaftaran' => $rp($team->payment_amount),
    ];

    if ((float) $team->service_fee > 0) {
        $rows['Biaya layanan'] = $rp($team->service_fee);
    }

    // Rows settled before gateway_tax existed report 0 and keep the single
    // combined label, exactly as they rendered before.
    if ((float) $team->gateway_fee > 0) {
        $taxed = (float) $team->gateway_tax > 0;
        $rows[$taxed ? 'Biaya payment gateway' : 'Biaya pembayaran']
            = $rp((float) $team->gateway_fee - (float) $team->gateway_tax);

        if ($taxed) {
            $rows['PPN'] = $rp($team->gateway_tax);
        }
    }

    $rows['Total dibayar'] = $rp($team->gross_amount);
    $rows['Dibayar'] = $team->paid_at?->timezone(config('wallet.timezone'))->translatedFormat('d F Y, H:i').' WIB';
@endphp

@component('mail::table')
| | |
|:--- |:--- |
@foreach ($rows as $label => $value)
| **{{ $label }}** | {{ $value }} |
@endforeach
@endcomponent

@component('mail::button', ['url' => $url])
Lihat Tim Kamu
@endcomponent

Simpan email ini sebagai bukti pembayaran.
@endcomponent
