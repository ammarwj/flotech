<?php

namespace App\Mail;

use App\Models\RaceEntry;
use App\Services\ParticipantDocumentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Entry confirmation for the runner, sent once their entry is paid.
 *
 * Twin of TicketPurchasedMail. The receipt is what is shown at the start line
 * in this phase, so unlike the ticket mail it links to the entry page *and*
 * attaches the PDF: a runner standing at a registration desk with no signal is
 * exactly the case a link alone does not cover.
 */
class RaceEntryPaidMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public RaceEntry $entry) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Pendaftaran kamu sudah lunas — '.$this->entry->event->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.race-entry-paid',
            with: [
                'entry' => $this->entry,
                'event' => $this->entry->event,
                'category' => $this->entry->category,
                'entryUrl' => rtrim((string) config('app.frontend_url'), '/').'/race-entries/'.$this->entry->id,
            ],
        );
    }

    /**
     * Invoice and receipt, in that order — mail clients list attachments the
     * way they were added, and the bill came before the proof of payment.
     *
     * A free entry has neither number and gets neither file; the confirmation
     * itself still goes out. Same shape, same reason as TicketPurchasedMail.
     *
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        $docs = app(ParticipantDocumentService::class);
        $entry = $this->entry;

        return collect(['invoice', 'receipt'])
            ->filter(fn (string $kind) => $entry->{"{$kind}_number"} !== null)
            ->map(fn (string $kind) => Attachment::fromData(
                fn () => $docs->bytes($kind, $entry),
                $docs->filename($kind, $entry),
            )->withMime('application/pdf'))
            ->values()
            ->all();
    }
}
