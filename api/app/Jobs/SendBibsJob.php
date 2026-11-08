<?php

namespace App\Jobs;

use App\Mail\BibIssuedMail;
use App\Models\RaceEntry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * Emails one runner their e-BIB.
 *
 * One job per entry, with no grouping step at all — the half of
 * SendIdCardsJob this does not need. That one groups by address because three
 * recipient pools answer "whose address is this?" two different ways; a runner
 * holds their own `race_entries.email`, so one entry is one mail.
 *
 * `bib_sent_at` is stamped only after the mail is handed to the transport,
 * which is why BibIssuedMail is sent with `sendNow()` despite being
 * ShouldQueue: a plain `send()` would re-queue it and return before transport,
 * marking a bib as delivered on a message that has not gone out.
 */
class SendBibsJob implements ShouldQueue
{
    use Queueable;

    /**
     * SMTP providers rate-limit per second, and this feature fans out harder
     * than the card one it borrows from: one mail per RUNNER rather than one
     * per address, so a 300-entry race dispatches 300 jobs in one loop. The
     * card job learned this the hard way — Mailtrap answers `550 Too many
     * emails per second` and, with the default `tries = 1`, every refusal was
     * final: the organizer saw a green toast over mail that never left.
     *
     * Retried rather than throttled at dispatch, because the limit belongs to
     * the provider and differs per plan. The backoff is per attempt, so a burst
     * spreads itself out.
     *
     * Idempotent to retry: `bib_sent_at` is written only after the transport
     * accepts, so an attempt that throws leaves the bib re-sendable.
     */
    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60, 120];

    public function __construct(public string $entryId) {}

    public function handle(): void
    {
        // Re-read rather than carrying the model: the batch that queued this
        // may have been a reprint, and `bib_png_key` was written in the same
        // run.
        $entry = RaceEntry::with(['event:id,name,start_date', 'category:id,name'])
            ->whereKey($this->entryId)
            ->whereNotNull('bib_png_key')
            ->first();

        // Nothing to attach, or nobody to attach it to. Not an error: an entry
        // whose sheet was never rendered legitimately has no key, and the
        // organizer was already told how many runners that covers before they
        // pressed send.
        if (! $entry || ! $entry->email) {
            return;
        }

        Mail::to($entry->email)->sendNow(new BibIssuedMail($entry));

        // A bare UPDATE rather than $entry->update(): every money and status
        // column on this table has exactly one writer, and a mail job must not
        // become a second door onto any of them by saving a dirty instance.
        RaceEntry::whereKey($entry->id)->update(['bib_sent_at' => Carbon::now()]);
    }
}
