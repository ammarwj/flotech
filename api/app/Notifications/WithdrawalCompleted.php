<?php

namespace App\Notifications;

use App\Models\Withdrawal;
use App\Support\MailLinks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The transfer left the platform. Real money moving with no word about it is the
 * kind of silence that turns into a support ticket, so this carries the proof:
 * the destination account and the bank's transfer reference.
 */
class WithdrawalCompleted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Withdrawal $withdrawal) {}

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
            ->subject('Dana sudah ditransfer — '.$this->withdrawal->reference)
            ->markdown('mail.withdrawal-completed', [
                'withdrawal' => $this->withdrawal,
                'url' => MailLinks::wallet(),
            ]);
    }
}
