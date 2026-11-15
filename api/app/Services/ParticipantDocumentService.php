<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\RaceEntry;
use App\Models\RaceEntryTransfer;
use App\Models\Team;
use App\Models\TicketOrder;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * The two documents a participant's payment produces: a ticket purchase, or a
 * team's registration fee.
 *
 * Twin of BillingDocumentService, with one difference that drives everything
 * else: the issuer here is the **organizer**, not the platform. That money is
 * credited to their wallet (WalletService::creditTicketOrder), so the sale is
 * theirs and the document carries their name.
 *
 * Kept apart from BillingDocumentService rather than folded into it: that one
 * is typed to EventPlanOrder, and widening it to a union would push a branch
 * into every one of its readers to answer a question only this class asks.
 *
 * **Four members, and the limit was reached deliberately rather than drifted
 * past.** The note that stood here said a fourth would be the signal to split,
 * and named the seam to cut along: the ISSUER, not the subject. A category
 * transfer has the same issuer, the same payer and the same two documents as the
 * entry it tops up — so by that criterion it belongs here, and splitting would
 * put two classes that differ in nothing but a handful of strings in front of
 * every reader. What the warning was really about still holds and is now live:
 * subject() is a four-arm dispatch, and the honest reading is that the shape
 * wants a `BillableSubject` contract on the models rather than a fifth arm.
 * Treat a fifth member as that work falling due, not as one more `instanceof`.
 *
 * Rendered on demand, never stored — same as the plan-order documents.
 */
class ParticipantDocumentService
{
    public function invoice(TicketOrder|Team|RaceEntry|RaceEntryTransfer $order): Response
    {
        return $this->render('invoice', $order);
    }

    public function receipt(TicketOrder|Team|RaceEntry|RaceEntryTransfer $order): Response
    {
        return $this->render('receipt', $order);
    }

    /**
     * The same document as raw bytes, for mail attachments.
     *
     * @param  'invoice'|'receipt'  $kind
     */
    public function bytes(string $kind, TicketOrder|Team|RaceEntry|RaceEntryTransfer $order): string
    {
        return $this->pdf($kind, $order)->output();
    }

    /** Filename the recipient sees: "Kwitansi-KW-T-2026-09-0002.pdf". */
    public function filename(string $kind, TicketOrder|Team|RaceEntry|RaceEntryTransfer $order): string
    {
        $number = $kind === 'receipt' ? $order->receipt_number : $order->invoice_number;

        return $this->label($kind).'-'.str_replace('/', '-', (string) ($number ?? $order->id)).'.pdf';
    }

    protected function render(string $kind, TicketOrder|Team|RaceEntry|RaceEntryTransfer $order): Response
    {
        return $this->pdf($kind, $order)->download($this->filename($kind, $order));
    }

    /**
     * The same document as HTML, before dompdf gets it.
     *
     * Public for the reason TicketPosterService::html() is: dompdf output is
     * compressed streams, so a test holding the bytes can only say that
     * *something* rendered — and that assertion stays green when the document
     * stops printing a discount row, or prints the net price where the
     * catalogue one belongs.
     *
     * @param  'invoice'|'receipt'  $kind
     */
    public function html(string $kind, TicketOrder|Team|RaceEntry|RaceEntryTransfer $order): string
    {
        return $this->view($kind, $order)->render();
    }

    protected function label(string $kind): string
    {
        return $kind === 'receipt' ? 'Kwitansi' : 'Invoice';
    }

    protected function pdf(string $kind, TicketOrder|Team|RaceEntry|RaceEntryTransfer $order): DomPdf
    {
        return Pdf::loadHTML($this->html($kind, $order))->setPaper('a4');
    }

    /** The view, resolved once so html() and pdf() cannot render differently. */
    protected function view(string $kind, TicketOrder|Team|RaceEntry|RaceEntryTransfer $order): View
    {
        return view("pdf.participant-{$kind}", [
            'order' => $order,
            'issuer' => config('billing'),
            // The row stores the tax apart from the fee, so the document shows
            // it apart too — and falls back to one combined line for rows
            // settled before that column existed, which report 0.
            'taxSplit' => (float) $order->gateway_tax > 0,
            // Only an unpaid manual bill needs the destination account — and on
            // this rail the money goes to the organizer's own bank, not ours.
            'bank' => $order->isManual() && ! $order->isSettled()
                ? $this->organizerAccount($order)
                : null,
            // Blade renders a child's sections before the layout runs, so the
            // formatters have to reach both — pass them in as view data.
            'money' => fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.'),
            'date' => fn ($d) => $d
                ? Carbon::parse($d)->timezone(config('wallet.timezone'))->locale('id')->translatedFormat('d F Y')
                : '—',
            ...$this->subject($order),
        ]);
    }

    /**
     * " — hari: 14, 15 Nov 2026" for an order that bought days, empty otherwise.
     *
     * Empty is the right answer for every order placed before per-day ticketing
     * existed, and for every category that does not sell by the day: there is
     * no day to name, and a document is not the place to explain that.
     *
     * Public for the reason TicketPosterService::html() is: dompdf output is
     * compressed streams, so a test holding the bytes can only say that
     * *something* rendered — and that assertion stays green when the document
     * stops naming the right days, which is the one thing this adds.
     */
    public function ticketDaysNote(TicketOrder $order): string
    {
        $dates = $order->event_dates ?? [];

        if ($dates === []) {
            return '';
        }

        $days = collect($dates)
            ->map(fn (string $date) => Carbon::parse($date)->locale('id')->translatedFormat('d M Y'))
            ->implode(', ');

        return ' — '.(count($dates) > 1 ? 'hari: ' : 'tanggal ').$days;
    }

    /**
     * Everything that differs between a ticket order and a registration fee.
     *
     * One method rather than two templates: the documents are the same
     * document, and keeping the difference to a handful of strings is what
     * stops them drifting apart.
     *
     * @return array<string, mixed>
     */
    protected function subject(TicketOrder|Team|RaceEntry|RaceEntryTransfer $order): array
    {
        if ($order instanceof TicketOrder) {
            $order->loadMissing('event.organization', 'category');

            return [
                'org' => $order->event?->organization,
                'payerName' => $order->buyer_name,
                'payerLines' => array_values(array_filter([$order->buyer_email, $order->buyer_phone])),
                'itemTitle' => $order->quantity.' × Tiket '.($order->category?->name ?? '—'),
                // The days, appended to the note rather than given a row of
                // their own: the template renders three strings and must not
                // have to know per-day ticketing exists — same reasoning as the
                // reused `bank_accounts` columns. `quantity` already states the
                // paid units, so what is missing is only *which* days they are.
                'itemNote' => 'Harga satuan '.$this->rupiah($order->unit_price)
                    .$this->ticketDaysNote($order),
                'itemAmount' => (float) $order->total_price,
                'methodLabel' => $this->methodLabel($order),
            ];
        }

        if ($order instanceof RaceEntryTransfer) {
            $order->loadMissing('event.organization', 'entry', 'fromCategory', 'toCategory');

            return [
                'org' => $order->event?->organization,
                'payerName' => $order->entry?->full_name ?? '—',
                'payerLines' => array_values(array_filter([$order->entry?->email, $order->entry?->phone])),
                // Both ends named, because that pair is the whole subject: a
                // bill reading "Pindah kategori" alone cannot be checked
                // against anything, and the runner is holding the original
                // receipt for the category on the left of the arrow.
                'itemTitle' => 'Pindah kategori — '
                    .($order->fromCategory?->name ?? '—').' → '.($order->toCategory?->name ?? '—'),
                // The arithmetic spelled out, because the number below is a
                // DIFFERENCE and no other document in this app prints one. The
                // period is named for the reason the entry's own note names it:
                // these are that window's prices, not today's, and without it
                // the two figures cannot be read against any price list.
                'itemNote' => 'Periode '.$order->period_name
                    .' · '.$this->rupiah($order->to_price).' − sudah dibayar '.$this->rupiah($order->paid_before),
                // The difference itself, NOT `to_price`: the runner is being
                // billed the top-up, and printing the target category's full
                // price over a total that is smaller reads as an error in the
                // document. No discount reconstruction here — a code is sold
                // against an entry, never against a move, so `discount_amount`
                // has no column on this row for the layout to find.
                'itemAmount' => (float) $order->amount,
                'methodLabel' => $this->methodLabel($order),
            ];
        }

        if ($order instanceof RaceEntry) {
            $order->loadMissing('event.organization', 'category');

            return [
                'org' => $order->event?->organization,
                'payerName' => $order->full_name,
                'payerLines' => array_values(array_filter([$order->email, $order->phone])),
                'itemTitle' => 'Biaya pendaftaran — '.($order->category?->name ?? '—'),
                // The period is what explains the number above it: the same
                // category costs three different amounts over a sale, and a
                // receipt that does not say which window it was bought in
                // cannot be checked against the price list. Read off the
                // entry's own snapshot, never through the relation — the
                // period may since have been repriced or deleted.
                'itemNote' => 'Periode '.$order->period_name,
                // The catalogue price, reconstructed: `price` is already net of
                // any discount, and the discount row the layout prints needs
                // something above it to be a discount *off*. Without a code the
                // two are equal. Same arithmetic as the plan row.
                'itemAmount' => (float) $order->price + (float) $order->discount_amount,
                'methodLabel' => $this->methodLabel($order),
            ];
        }

        $order->loadMissing('event.organization', 'category');

        return [
            'org' => $order->event?->organization,
            'payerName' => $order->name,
            'payerLines' => array_values(array_filter([$order->contact_name, $order->contact_phone])),
            'itemTitle' => 'Biaya pendaftaran — '.($order->category?->name ?? '—'),
            'itemNote' => 'Tim '.$order->name,
            // Reconstructed the same way as the entry above: `payment_amount`
            // is already net of any discount.
            'itemAmount' => (float) $order->payment_amount + (float) $order->discount_amount,
            'methodLabel' => $this->methodLabel($order),
        ];
    }

    /**
     * How it was paid.
     *
     * Derived from the channel rather than read from a `payment_type` column:
     * unlike EventPlanOrder these two have none, and the channel is what the
     * buyer actually picked. The two off-platform rails answer before the
     * lookup, because neither has a channel to name.
     */
    protected function methodLabel(TicketOrder|Team|RaceEntry|RaceEntryTransfer $order): string
    {
        if ($order->isManual()) {
            return 'Transfer manual (diverifikasi penyelenggara)';
        }

        // Cash taken at the venue. Without this arm `onsite` falls through to
        // the channel lookup, finds no `payment_fees.channels.onsite`, and a
        // cash receipt claims the money came through a payment gateway.
        if ($order->payment_method === 'onsite') {
            return 'Tunai di loket';
        }

        return config("payment_fees.channels.{$order->payment_channel}.label")
            ?? 'Payment gateway';
    }

    /** Where a manual transfer goes: the organizer's primary account. */
    protected function organizerAccount(TicketOrder|Team|RaceEntry|RaceEntryTransfer $order): ?BankAccount
    {
        return $order->event?->organization?->bankAccounts()->where('is_primary', true)->first();
    }

    protected function rupiah(mixed $n): string
    {
        return 'Rp '.number_format((float) $n, 0, ',', '.');
    }
}
