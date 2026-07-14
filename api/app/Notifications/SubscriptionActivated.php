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
 * Proof of payment, with the receipt PDF attached. Sent from
 * SubscriptionService::activate(), which is idempotent — Midtrans re-delivers
 * webhooks, and a second receipt must never land in the inbox for one payment.
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
            ->attachData(
                $docs->bytes('receipt', $sub),
                $docs->filename('receipt', $sub),
                ['mime' => 'application/pdf'],
            );
    }
}
