<?php

namespace App\Notifications;

use App\Models\Team;
use App\Support\MailLinks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The organizer's verdict on a registration — the thing a manager actually waits
 * for. One class for every outcome so the copy for "rejected" can never drift
 * out of sync with the copy for "approved".
 */
class TeamStatusChanged extends Notification implements ShouldQueue
{
    use Queueable;

    /** Outcomes worth an email. 'pending' is not one: nothing happened yet. */
    public const NOTIFIABLE = ['approved', 'rejected', 'disqualified', 'withdrawn'];

    public function __construct(public Team $team, public string $status) {}

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

        [$subject, $type, $title, $body] = match ($this->status) {
            'approved' => [
                'Tim kamu disetujui — '.$event->name,
                'success',
                'Disetujui',
                'Tim kamu resmi ikut serta. Jadwal pertandingan akan muncul di halaman tim begitu penyelenggara menerbitkannya.',
            ],
            'rejected' => [
                'Pendaftaran tim ditolak — '.$event->name,
                'error',
                'Ditolak',
                'Penyelenggara tidak menerima pendaftaran ini. Hubungi mereka langsung kalau kamu merasa ini keliru.',
            ],
            'disqualified' => [
                'Tim kamu didiskualifikasi — '.$event->name,
                'error',
                'Didiskualifikasi',
                'Tim kamu dikeluarkan dari turnamen oleh penyelenggara.',
            ],
            default => [
                'Tim kamu mengundurkan diri — '.$event->name,
                'warning',
                'Mengundurkan diri',
                'Tim kamu tercatat mundur dari turnamen ini.',
            ],
        };

        return (new MailMessage)
            ->subject($subject)
            ->markdown('mail.team-status-changed', [
                'team' => $this->team,
                'event' => $event,
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'url' => MailLinks::team($this->team),
            ]);
    }
}
