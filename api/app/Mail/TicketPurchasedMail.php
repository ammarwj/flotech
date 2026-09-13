<?php

namespace App\Mail;

use App\Models\TicketOrder;
use App\Services\ParticipantDocumentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Purchase confirmation for the buyer, sent once an order is paid.
 *
 * The QR codes themselves are not embedded — mail clients block remote images
 * and there is no server-side QR renderer — so the mail links to the public
 * e-ticket page (`/tickets/{order}`), which draws them client-side.
 */
class TicketPurchasedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public TicketOrder $order) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Tiket kamu sudah aktif — '.$this->order->event->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.ticket-purchased',
            with: [
                'order' => $this->order,
                'event' => $this->order->event,
                'category' => $this->order->category,
                'ticketUrl' => rtrim((string) config('app.frontend_url'), '/').'/tickets/'.$this->order->id,
            ],
        );
    }

    /**
     * Invoice and receipt, in that order — mail clients list attachments the
     * way they were added, and the bill came before the proof of payment.
     *
     * A free ticket has neither number and gets neither file; the confirmation
     * itself still goes out.
     *
     * @return list<\Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        $docs = app(ParticipantDocumentService::class);
        $order = $this->order;

        return collect(['invoice', 'receipt'])
            ->filter(fn (string $kind) => $order->{"{$kind}_number"} !== null)
            ->map(fn (string $kind) => Attachment::fromData(
                fn () => $docs->bytes($kind, $order),
                $docs->filename($kind, $order),
            )->withMime('application/pdf'))
            ->values()
            ->all();
    }
}
