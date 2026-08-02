<?php

namespace App\Notifications;

use App\Models\EventPlanOrder;
use App\Support\MailLinks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A reminder that a paid plan is still waiting for an event.
 *
 * Not a receipt and not a bill — nothing is owed. The credit never expires, so
 * this exists purely so money already taken does not sit forgotten in an orders
 * list the organizer has no reason to open.
 *
 * No PDF attached, unlike the invoice and receipt mails: nothing has happened
 * that needs documenting, and a second copy of a receipt they already have
 * would read as a second charge.
 */
class PlanOrderIdle extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public EventPlanOrder $order, public int $idleDays) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $order = $this->order->loadMissing('plan', 'organization');

        return (new MailMessage)
            ->subject('Paket '.($order->plan?->name ?? '').' kamu belum dipakai — '.config('brand.name'))
            ->markdown('mail.plan-order-idle', [
                'order' => $order,
                'idleDays' => $this->idleDays,
                'url' => MailLinks::billing(),
            ]);
    }
}
