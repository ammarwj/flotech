<?php

namespace App\Mail;

use App\Models\IdCard;
use App\Services\IdCardService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * Delivers one recipient's ID cards, PNGs attached.
 *
 * Takes a *collection*, unlike CertificateIssuedMail, and that is the whole
 * shape of this feature on the mail side: players and team officials have no
 * email column at all, so a team's only address is its manager's account — and
 * one card per mail would send the manager of a twenty-player squad twenty
 * emails each carrying one PNG. Event personnel arrive here as a collection of
 * one.
 *
 * ShouldQueue like every other Mailable. It is still dispatched from inside
 * SendIdCardsJob, which sends it with `sendNow()` on purpose — so the job can
 * stamp `sent_at` only once the mail has actually gone out.
 */
class IdCardsIssuedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  Collection<int, IdCard>  $cards  all for one address, one event
     */
    public function __construct(public Collection $cards) {}

    public function envelope(): Envelope
    {
        $event = $this->cards->first()?->event;

        return new Envelope(
            subject: ($this->cards->count() > 1 ? 'ID card tim kamu — ' : 'ID card kamu — ')
                .($event?->name ?? 'event'),
        );
    }

    public function content(): Content
    {
        $single = $this->cards->count() === 1 ? $this->cards->first() : null;

        return new Content(
            markdown: 'mail.id-cards-issued',
            with: [
                'cards' => $this->cards,
                'event' => $this->cards->first()?->event,
                // Only for a single card. A button pointing at one of twenty
                // attachments points at the wrong one for nineteen people, so
                // the multi-card body lists the numbers in a table instead.
                'verifyUrl' => $single?->verifyUrl(),
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return $this->cards
            ->filter(fn (IdCard $card) => (bool) $card->png_key)
            ->map(fn (IdCard $card) => Attachment::fromStorageDisk(
                // Asked, not re-derived: a fourth reader of "R2 or local?" that
                // disagrees yields a mail with no attachment, which sends
                // successfully and stamps `sent_at` on nothing.
                IdCardService::diskName(),
                (string) $card->png_key,
            )
                ->as($card->filename())
                ->withMime('image/png'))
            ->values()
            ->all();
    }
}
