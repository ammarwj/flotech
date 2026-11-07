<?php

namespace App\Notifications;

use App\Models\RefundPayout;
use App\Support\MailLinks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The platform transferred the refund to the organizer, with proof.
 *
 * Carries two things the organizer cannot get anywhere else: the proof that the
 * money arrived, so they can answer a participant who is chasing it, and the
 * reminder that hop two is theirs — the platform does not hold the
 * participant's bank details and never has, so forwarding it is the organizer's
 * own step. Without this mail the money lands in their account unannounced and
 * the participant waits on somebody who does not know they were paid.
 */
class RefundTransferCompleted extends Notification implements ShouldQueue
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
            ->subject('Dana refund sudah kami transfer — '.$this->payout->recipient_name)
            ->markdown('mail.refund-transfer-completed', [
                'payout' => $this->payout,
                'url' => MailLinks::base().'/organizer/refund-payouts',
            ]);
    }
}
