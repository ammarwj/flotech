<?php

namespace App\Services;

use App\Models\Subscription;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renders the two billing documents a subscription can produce.
 *
 * Invoice = the bill (exists from checkout, paid or not).
 * Receipt = proof of payment (exists only once paid_at is set).
 *
 * The issuer is always the platform, read from config/billing.php — never the
 * organizer, who is the one being billed.
 */
class BillingDocumentService
{
    public function invoice(Subscription $subscription): Response
    {
        return $this->render('invoice', $subscription, $subscription->invoice_number);
    }

    public function receipt(Subscription $subscription): Response
    {
        return $this->render('receipt', $subscription, $subscription->receipt_number);
    }

    /**
     * The same document as raw bytes, for mail attachments.
     *
     * @param  'invoice'|'receipt'  $kind
     */
    public function bytes(string $kind, Subscription $subscription): string
    {
        return $this->pdf($kind, $subscription)->output();
    }

    /**
     * Filename the recipient sees: "Kwitansi-KW-2026-07-0002.pdf".
     *
     * @param  'invoice'|'receipt'  $kind
     */
    public function filename(string $kind, Subscription $subscription): string
    {
        $number = $kind === 'receipt' ? $subscription->receipt_number : $subscription->invoice_number;

        return $this->label($kind).'-'.$this->slug($number, $subscription).'.pdf';
    }

    protected function render(string $view, Subscription $subscription, ?string $number): Response
    {
        return $this->pdf($view, $subscription)
            ->download($this->label($view).'-'.$this->slug($number, $subscription).'.pdf');
    }

    protected function label(string $view): string
    {
        return $view === 'receipt' ? 'Kwitansi' : 'Invoice';
    }

    /** INV/2026/07/0002 → INV-2026-07-0002; slashes are not filename-safe. */
    protected function slug(?string $number, Subscription $subscription): string
    {
        return str_replace('/', '-', (string) ($number ?? $subscription->id));
    }

    protected function pdf(string $view, Subscription $subscription): DomPdf
    {
        $subscription->loadMissing('plan', 'organization');

        $pdf = Pdf::loadView("pdf.{$view}", [
            'subscription' => $subscription,
            'issuer' => config('billing'),
            'dueAt' => Carbon::parse($subscription->created_at)->addDays((int) config('billing.due_days', 7)),
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
