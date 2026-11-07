<?php

namespace App\Notifications;

use App\Models\RefundPayout;
use App\Support\MailLinks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A refund was granted, so the platform owes somebody a transfer.
 *
 * This notification is the gap it was built to close. A refund reversed the
 * organizer's credit the instant it was granted — balance down, place back on
 * offer — and then nothing happened, because no refund rail notified anybody
 * and race entries were not even listed on `/admin/payments`. The money sits in
 * the platform's own Midtrans account, so silence here is a participant waiting
 * indefinitely for a refund everybody believes was already handled.
 *
 * Addressed to super admins, not the organizer: hop one is the platform's debt.
 * The organizer hears from `RefundTransferCompleted` once it is paid.
 */
class RefundAwaitingTransfer extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public RefundPayout $payout) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Refund menunggu transfer — '.$this->payout->organization?->name)
            ->markdown('mail.refund-awaiting-transfer', [
                'payout' => $this->payout,
                'url' => MailLinks::base().'/admin/refund-payouts',
            ]);
    }
}
