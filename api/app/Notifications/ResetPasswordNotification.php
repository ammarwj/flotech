<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Replaces Laravel's built-in ResetPassword, which speaks English in an app whose
 * every other word is Indonesian.
 *
 * The reset link points at the web app, not the API: the token is exchanged from
 * the /reset-password page. Building it here keeps the URL next to the copy that
 * explains it, instead of stranded in a service provider.
 */
class ResetPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $token) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = sprintf(
            '%s/reset-password?token=%s&email=%s',
            rtrim((string) config('app.frontend_url'), '/'),
            $this->token,
            urlencode($notifiable->getEmailForPasswordReset()),
        );

        $minutes = config('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject('Atur ulang password — '.config('brand.name'))
            ->markdown('mail.reset-password', [
                'name' => $notifiable->full_name,
                'url' => $url,
                'minutes' => $minutes,
            ]);
    }
}
