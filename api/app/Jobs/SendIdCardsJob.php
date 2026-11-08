<?php

namespace App\Jobs;

use App\Mail\IdCardsIssuedMail;
use App\Models\IdCard;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * Emails one address its ID cards.
 *
 * One job per *destination*, not per card, and the grouping happens upstream in
 * IdCardService::groupForSending() rather than here or in the controller —
 * "whose address is this?" is answered differently by all three recipient pools
 * (players and officials borrow their team manager's, personnel carry their
 * own), so a second place deriving it would be a second answer.
 *
 * `sent_at` is stamped only after the mail is handed to the transport, which is
 * why IdCardsIssuedMail is sent with `sendNow()` despite being ShouldQueue: a
 * plain `send()` would re-queue it and return before transport, marking cards
 * as delivered on a message that has not gone out. One mail carries N cards, so
 * the stamp covers N rows in one write — a transport failure throws before it
 * and leaves all N re-sendable, never half.
 */
class SendIdCardsJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, string>  $cardIds  all for one address, one event
     */
    public function __construct(public array $cardIds) {}

    public function handle(): void
    {
        // Re-read rather than carrying models: the batch that queued this may
        // have been a reprint, and the PNG keys were written in the same run.
        $cards = IdCard::with('event:id,name,start_date')
            ->whereIn('id', $this->cardIds)
            ->whereNotNull('png_key')
            ->get();

        $email = $cards->first()?->recipient_email;

        // Nothing to attach, or nobody to attach it to. Not an error: a team
        // registered without a manager account legitimately has no address, and
        // the organizer was already told how many cards that covers before they
        // pressed send.
        if (! $email || $cards->isEmpty()) {
            return;
        }

        Mail::to($email)->sendNow(new IdCardsIssuedMail($cards));

        IdCard::whereIn('id', $cards->pluck('id'))->update(['sent_at' => Carbon::now()]);
    }
}
