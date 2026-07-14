<?php

namespace App\Notifications;

use App\Models\Team;
use App\Support\MailLinks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Receipt for the manager who just registered a team: it arrived, and it is
 * waiting on the organizer. Without this, the only way to know the form went
 * through is to log back in and look.
 */
class TeamRegistrationSubmitted extends Notification implements ShouldQueue
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
            ->subject('Pendaftaran tim diterima — '.$event->name)
            ->markdown('mail.team-registration-submitted', [
                'team' => $this->team,
                'event' => $event,
                'url' => MailLinks::team($this->team),
            ]);
    }
}
