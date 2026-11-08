<?php

namespace App\Mail;

use App\Models\RaceEntry;
use App\Services\BibService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Delivers one runner's e-BIB, PNG attached.
 *
 * Takes a single entry, unlike IdCardsIssuedMail which takes a collection —
 * and that difference is the whole of why this feature has no grouping step.
 * A card borrows its team manager's address, so twenty players share one
 * mailbox; a runner holds their own `race_entries.email`, so one entry is one
 * mail is one attachment.
 *
 * ShouldQueue like every other Mailable. It is still dispatched from inside
 * SendBibsJob, which sends it with `sendNow()` on purpose — so the job can
 * stamp `bib_sent_at` only once the mail has actually gone out.
 */
class BibIssuedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public RaceEntry $entry) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'e-BIB kamu — '.($this->entry->event?->name ?? 'event'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.bib-issued',
            with: [
                'entry' => $this->entry,
                'event' => $this->entry->event,
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        if (! $this->entry->bib_png_key) {
            return [];
        }

        return [
            Attachment::fromStorageDisk(
                // Asked, not re-derived: a third reader of "R2 or local?" that
                // disagrees yields a mail with no attachment, which sends
                // successfully and stamps `bib_sent_at` on nothing.
                BibService::diskName(),
                (string) $this->entry->bib_png_key,
            )
                ->as(app(BibService::class)->filename($this->entry))
                ->withMime('image/png'),
        ];
    }
}
