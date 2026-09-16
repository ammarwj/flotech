<?php

namespace App\Notifications;

use App\Models\Event;
use App\Models\EventPersonnel;
use App\Support\MailLinks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a referee or match staff they have been put on an event's crew.
 *
 * Two outcomes, one class — the same reason TeamStatusChanged gives: the copy
 * for someone who already had an account must not drift away from the copy for
 * someone whose account was just created for them. They differ in exactly one
 * thing, and that thing is dangerous enough to be worth seeing side by side:
 * the invite prints the default password, the assignment must never mention a
 * password at all, because that account's password belongs to its owner and
 * this flow never touched it.
 *
 * The password in the body is a deliberate deviation from what this codebase
 * says about credentials (Admin\UserController::resetPassword: "sampaikan
 * password barunya lewat kanal yang aman"). The user chose the trade-off with
 * both halves attached: mail the default *and* force a rotation at first login.
 * What bounds it is that the default stops opening anything once it has been
 * read — enforced server-side by EnsurePasswordRotated, not by this mail asking
 * nicely.
 */
class PersonnelInvited extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  bool  $withPassword  True only when this flow created the account.
     *                              An existing user gets the assignment copy.
     */
    public function __construct(
        public EventPersonnel $personnel,
        public Event $event,
        public bool $withPassword,
        public ?string $password = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // roleLabel(), not KIND_LABELS: "Wasit Utama" is what the organizer
        // typed for this person, and the coarse kind is only its fallback.
        $role = $this->personnel->roleLabel();

        [$subject, $title, $body] = $this->withPassword
            ? [
                'Akses petugas '.$this->event->name,
                'Akun petugas kamu sudah dibuat',
                'Penyelenggara '.$this->event->name.' menugaskan kamu sebagai '.$role.'. '
                    .'Akun untuk masuk sudah dibuatkan dengan password sementara di bawah ini.',
            ]
            : [
                'Penugasan petugas '.$this->event->name,
                'Kamu ditugaskan di event baru',
                'Penyelenggara '.$this->event->name.' menugaskan kamu sebagai '.$role.'. '
                    .'Masuk dengan akun yang sudah kamu punya — password kamu tidak berubah.',
            ];

        return (new MailMessage)
            ->subject($subject)
            ->markdown('mail.personnel-invited', [
                'personnel' => $this->personnel,
                'event' => $this->event,
                'role' => $role,
                'title' => $title,
                'body' => $body,
                'withPassword' => $this->withPassword,
                'email' => $this->personnel->email,
                'password' => $this->password,
                'url' => MailLinks::officiating($this->event),
            ]);
    }
}
