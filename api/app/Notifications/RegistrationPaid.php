<?php

namespace App\Notifications;

use App\Models\Team;
use App\Support\MailLinks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Proof of payment for a registration fee. Sent from RegistrationService::markPaid(),
 * which early-returns on an already-paid team — so a re-delivered Midtrans webhook
 * cannot land a second copy of this in the inbox.
 */
class RegistrationPaid extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Team $team) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $event = $this->team->event;

        return (new MailMessage)
            ->subject('Pembayaran diterima — '.$event->name)
            ->markdown('mail.registration-paid', [
                'team' => $this->team,
                'event' => $event,
                'url' => MailLinks::team($this->team),
            ]);
    }
}
