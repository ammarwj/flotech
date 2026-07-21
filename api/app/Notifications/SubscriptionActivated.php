<?php

namespace App\Notifications;

use App\Models\Subscription;
use App\Services\BillingDocumentService;
use App\Support\MailLinks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Proof of payment. Sent from SubscriptionService::activate(), which is
 * idempotent — Midtrans re-delivers webhooks, and a second receipt must never
 * land in the inbox for one payment.
 *
 * Carries BOTH documents: the invoice (what was billed) and the receipt (proof
 * it was paid). The receipt alone is not enough, because there are paths where
 * the invoice email never went out — checkout only mails it while the bill is
 * genuinely outstanding, so an org that pays instantly (or any org in a dev /
 * gateway-less setup, where openSnap() activates on the spot) would otherwise
 * end up with a receipt for an invoice number it has never seen. Finance
 * generally wants the pair filed together anyway.
 */
class SubscriptionActivated extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Subscription $subscription) {}

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
        $sub = $this->subscription->loadMissing('plan', 'organization');

        return (new MailMessage)
            ->subject('Langganan '.$sub->plan->name.' aktif — '.config('brand.name'))
            ->markdown('mail.subscription-activated', [
                'subscription' => $sub,
                'url' => MailLinks::subscription(),
            ])
            // Invoice first: it's the document that came first, and mail clients
            // list attachments in the order they were added.
            ->attachData(
                $docs->bytes('invoice', $sub),
                $docs->filename('invoice', $sub),
                ['mime' => 'application/pdf'],
            )
            ->attachData(
                $docs->bytes('receipt', $sub),
                $docs->filename('receipt', $sub),
                ['mime' => 'application/pdf'],
            );
    }
}
