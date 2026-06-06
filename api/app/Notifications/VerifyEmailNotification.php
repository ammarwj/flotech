<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

class VerifyEmailNotification extends Notification
{
    use Queueable;

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $signedUrl = URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes(60),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ],
        );

        return (new MailMessage)
            ->subject('Verifikasi alamat email — flo-event')
            ->greeting('Halo '.$notifiable->full_name.'!')
            ->line('Terima kasih telah mendaftar di flo-event. Klik tombol di bawah untuk memverifikasi email kamu.')
            ->action('Verifikasi Email', $signedUrl)
            ->line('Tautan ini berlaku 60 menit. Abaikan email ini jika kamu tidak mendaftar.');
    }
}
