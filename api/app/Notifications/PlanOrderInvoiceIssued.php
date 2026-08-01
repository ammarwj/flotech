<?php

namespace App\Notifications;

use App\Models\EventPlanOrder;
use App\Services\BillingDocumentService;
use App\Support\MailLinks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * The bill, the moment it exists. A plan order row is its own invoice — an
 * unpaid one is still a valid document — so the PDF is attached from checkout,
 * not held back until payment.
 */
class PlanOrderInvoiceIssued extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public EventPlanOrder $order) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $docs = app(BillingDocumentService::class);
        $sub = $this->order->loadMissing('plan', 'organization');
        $dueAt = Carbon::parse($sub->created_at)->addDays((int) config('billing.due_days', 7));

        return (new MailMessage)
            ->subject('Tagihan '.$sub->invoice_number.' — '.config('brand.name'))
            ->markdown('mail.plan-order-invoice-issued', [
                'order' => $sub,
                'dueAt' => $dueAt,
                'url' => MailLinks::billing(),
            ])
            ->attachData(
                $docs->bytes('invoice', $sub),
                $docs->filename('invoice', $sub),
                ['mime' => 'application/pdf'],
            );
    }
}
