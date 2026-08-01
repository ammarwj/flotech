<?php

namespace App\Services;

use App\Models\SiteSetting;
use App\Models\EventPlanOrder;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renders the two billing documents a plan order can produce.
 *
 * Invoice = the bill (exists from checkout, paid or not).
 * Receipt = proof of payment (exists only once paid_at is set).
 *
 * The issuer is always the platform, read from config/billing.php — never the
 * organizer, who is the one being billed.
 */
class BillingDocumentService
{
    public function invoice(EventPlanOrder $order): Response
    {
        return $this->render('invoice', $order, $order->invoice_number);
    }

    public function receipt(EventPlanOrder $order): Response
    {
        return $this->render('receipt', $order, $order->receipt_number);
    }

    /**
     * The same document as raw bytes, for mail attachments.
     *
     * @param  'invoice'|'receipt'  $kind
     */
    public function bytes(string $kind, EventPlanOrder $order): string
    {
        return $this->pdf($kind, $order)->output();
    }

    /**
     * Filename the recipient sees: "Kwitansi-KW-2026-07-0002.pdf".
     *
     * @param  'invoice'|'receipt'  $kind
     */
    public function filename(string $kind, EventPlanOrder $order): string
    {
        $number = $kind === 'receipt' ? $order->receipt_number : $order->invoice_number;

        return $this->label($kind).'-'.$this->slug($number, $order).'.pdf';
    }

    protected function render(string $view, EventPlanOrder $order, ?string $number): Response
    {
        return $this->pdf($view, $order)
            ->download($this->label($view).'-'.$this->slug($number, $order).'.pdf');
    }

    protected function label(string $view): string
    {
        return $view === 'receipt' ? 'Kwitansi' : 'Invoice';
    }

    /** INV/2026/07/0002 → INV-2026-07-0002; slashes are not filename-safe. */
    protected function slug(?string $number, EventPlanOrder $order): string
    {
        return str_replace('/', '-', (string) ($number ?? $order->id));
    }

    protected function pdf(string $view, EventPlanOrder $order): DomPdf
    {
        $order->loadMissing('plan', 'organization');

        $pdf = Pdf::loadView("pdf.{$view}", [
            'order' => $order,
            'issuer' => config('billing'),
            'dueAt' => Carbon::parse($order->created_at)->addDays((int) config('billing.due_days', 7)),
            // Only an unpaid manual bill needs it: an invoice telling the
            // organizer to transfer, without saying where, can't be acted on
            // outside the app — which is the whole point of a PDF.
            'bank' => $order->isManual() && ! $order->isSettled()
                ? SiteSetting::current()
                : null,
            // Blade renders a child's sections before the layout runs, so the
            // formatters have to reach both — pass them in as view data.
            'money' => fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.'),
            'date' => fn ($d) => $d
                ? Carbon::parse($d)->timezone(config('wallet.timezone'))->locale('id')->translatedFormat('d F Y')
                : '—',
        ])->setPaper('a4');

        return $pdf;
    }
}
