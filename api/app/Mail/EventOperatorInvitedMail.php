<?php

namespace App\Mail;

use App\Models\EventOperatorInvite;
use App\Support\MailLinks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Invites an email address to become an event operator for one event.
 *
 * `ShouldQueue` + dispatched `afterCommit()` by `EventOperatorService`, the
 * same pair every other invite/notification mail in this codebase uses — the
 * invite row must exist before a worker can pick the job up.
 *
 * One mailable for both "first time" and "resend": the token in the link is
 * always the row's CURRENT token, so a resend that rotated it just means an
 * older copy of this same mail now points at a dead link — exactly the
 * invalidation `resend()` promises.
 */
class EventOperatorInvitedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public EventOperatorInvite $invite) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Undangan jadi operator — '.$this->invite->event->name,
        );
    }

    public function content(): Content
    {
        $invite = $this->invite->loadMissing(['event.organization', 'invitedBy']);

        return new Content(
            markdown: 'mail.event-operator-invited',
            with: [
                'invite' => $invite,
                'event' => $invite->event,
                'organization' => $invite->event->organization,
                'invitedBy' => $invite->invitedBy,
                'permissionLabels' => $this->permissionLabels($invite),
                'url' => MailLinks::operatorInvite($invite),
            ],
        );
    }

    /** @return array<int, string> */
    protected function permissionLabels(EventOperatorInvite $invite): array
    {
        $labels = [
            'ticketing' => 'Tiket & check-in',
            'scheduling' => 'Jadwal & hasil pertandingan',
            'registration' => 'Pendaftaran peserta',
            'documents' => 'Sertifikat, ID card, bib & media',
        ];

        return array_values(array_intersect_key($labels, array_flip($invite->permissions())));
    }
}
