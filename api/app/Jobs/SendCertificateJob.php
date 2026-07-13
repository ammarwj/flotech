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
 * delivered.
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

        Mail::to($email)->send(new CertificateIssuedMail($this->certificate));

        $this->certificate->update(['sent_at' => Carbon::now()]);
    }
}
