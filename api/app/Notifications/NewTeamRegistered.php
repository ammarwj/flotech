<?php

namespace App\Notifications;

use App\Models\Team;
use App\Support\MailLinks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the organizer a team is waiting on their verdict, so approving isn't
 * gated on them happening to open the dashboard.
 */
class NewTeamRegistered extends Notification implements ShouldQueue
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
            ->subject('Tim baru mendaftar: '.$this->team->name)
            ->markdown('mail.new-team-registered', [
                'team' => $this->team,
                'event' => $event,
                'url' => MailLinks::registrations($event->id),
            ]);
    }
}
