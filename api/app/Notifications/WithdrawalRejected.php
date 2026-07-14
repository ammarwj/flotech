<?php

namespace App\Notifications;

use App\Models\Withdrawal;
use App\Support\MailLinks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The payout was refused and the funds went back to the available balance. The
 * reason travels with it — an organizer who doesn't know what to fix will simply
 * request the same thing again.
 */
class WithdrawalRejected extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Withdrawal $withdrawal, public string $reason) {}

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
            ->subject('Penarikan dana ditolak — '.$this->withdrawal->reference)
            ->markdown('mail.withdrawal-rejected', [
                'withdrawal' => $this->withdrawal,
                'reason' => $this->reason,
                'url' => MailLinks::wallet(),
            ]);
    }
}
