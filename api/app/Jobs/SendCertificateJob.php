<?php

namespace App\Jobs;

use App\Mail\CertificateIssuedMail;
use App\Models\Certificate;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * Emails one issued certificate to its recipient.
 *
 * `sent_at` is written only after the mail is handed to the transport, so a
 * failed send leaves the certificate re-sendable instead of silently marked as
 * delivered. CertificateIssuedMail is ShouldQueue, so we send it with
 * `sendNow()` — a plain `send()` would re-queue the mail and return before
 * transport, setting `sent_at` on a message that hasn't gone out yet.
 */
class SendCertificateJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public Certificate $certificate) {}

    public function handle(): void
    {
        $email = $this->certificate->recipient_email;

        if (! $email || ! $this->certificate->pdf_key) {
            return;
        }

        Mail::to($email)->sendNow(new CertificateIssuedMail($this->certificate));

        $this->certificate->update(['sent_at' => Carbon::now()]);
    }
}
