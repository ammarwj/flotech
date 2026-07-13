<?php

namespace App\Services;

use App\Models\Subscription;
use Barryvdh\DomPDF\Facade\Pdf;
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

    protected function render(string $view, Subscription $subscription, ?string $number): Response
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

        $label = $view === 'receipt' ? 'Kwitansi' : 'Invoice';
        // INV/2026/07/0002 → INV-2026-07-0002; slashes are not filename-safe.
        $slug = str_replace('/', '-', (string) ($number ?? $subscription->id));

        return $pdf->download("{$label}-{$slug}.pdf");
    }
}
