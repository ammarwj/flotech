@component('mail::message')
# Pendaftaran berhasil 🏃

Halo **{{ $entry->full_name }}**, pendaftaranmu untuk **{{ $event->name }}** sudah lunas.

@php
    // Built here rather than with @if inside the table, for the reason spelled
    // out in ticket-purchased.blade.php: a Blade directive on its own line
    // breaks the run of `|` rows markdown needs to see a table at all. Same
    // rows, same order and same conditions as the PDF — a mail that totals
    // differently from its own attachment is worse than one that omits the
    // split.
    $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');

    $rows = [
        'Event' => $event->name,
        'Tanggal' => $event->start_date?->translatedFormat('d F Y') ?? '—',
        'Lokasi' => $event->location_name ?: '—',
        'Kategori' => $category?->name ?? '—',
        // The period is what explains the number below it: the same category
        // costs three different amounts over a sale. Read off the entry's own
        // snapshot, never the relation — the period may since have been
        // repriced or deleted.
        'Periode' => $entry->period_name,
        // The catalogue price, reconstructed: `price` is already net of the
        // discount, and the row below has to be a discount *off* something.
        // Same arithmetic as the PDF.
        'Biaya pendaftaran' => $rp((float) $entry->price + (float) $entry->discount_amount),
    ];

    if ((float) $entry->discount_amount > 0) {
        $rows['Diskon '.$entry->discount_code] = '-'.$rp($entry->discount_amount);
    }

    // One runner, one place, so there is no arithmetic to spell out here the
    // way the ticket mail does for a basket of three.
    if ((float) $entry->service_fee > 0) {
        $rows['Biaya layanan'] = $rp($entry->service_fee);
    }

    if ((float) $entry->gateway_fee > 0) {
        $taxed = (float) $entry->gateway_tax > 0;
        $rows[$taxed ? 'Biaya payment gateway' : 'Biaya pembayaran']
            = $rp((float) $entry->gateway_fee - (float) $entry->gateway_tax);

        if ($taxed) {
            $rows['PPN'] = $rp($entry->gateway_tax);
        }
    }

    $rows['Total dibayar'] = $rp($entry->gross_amount);
@endphp

@component('mail::table')
| | |
|:--- |:--- |
@foreach ($rows as $label => $value)
| **{{ $label }}** | {{ $value }} |
@endforeach
@endcomponent

@component('mail::button', ['url' => $entryUrl])
Lihat Bukti Pendaftaran
@endcomponent

Kwitansi pendaftaranmu terlampir di email ini. **Tunjukkan kwitansi tersebut saat pengambilan race pack di hari H** — cetak atau simpan di ponsel.

Terima kasih,<br>
{{ config('app.name') }}
@endcomponent
