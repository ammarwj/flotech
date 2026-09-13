<?php

namespace App\Notifications;

use App\Models\Team;
use App\Services\ParticipantDocumentService;
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
        $docs = app(ParticipantDocumentService::class);

        $mail = (new MailMessage)
            ->subject('Pembayaran diterima — '.$event->name)
            ->markdown('mail.registration-paid', [
                'team' => $this->team,
                'event' => $event,
                'url' => MailLinks::team($this->team),
            ]);

        // Invoice first: it is the document that came first, and mail clients
        // list attachments in the order they were added. A free entry has
        // neither number and gets neither file.
        foreach (['invoice', 'receipt'] as $kind) {
            if ($this->team->{"{$kind}_number"} !== null) {
                $mail->attachData(
                    $docs->bytes($kind, $this->team),
                    $docs->filename($kind, $this->team),
                    ['mime' => 'application/pdf'],
                );
            }
        }

        return $mail;
    }
}
