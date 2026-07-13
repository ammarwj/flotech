<?php

namespace App\Mail;

use App\Models\Certificate;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Delivers one issued certificate to its recipient, PDF attached.
 *
 * Not queued itself — SendCertificateJob wraps it, so the job can record
 * `sent_at` only once the mail has actually gone out.
 */
class CertificateIssuedMail extends Mailable
{
    use SerializesModels;

    public function __construct(public Certificate $certificate) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Sertifikat kamu — '.$this->certificate->event->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.certificate-issued',
            with: [
                'certificate' => $this->certificate,
                'event' => $this->certificate->event,
                'verifyUrl' => $this->certificate->verifyUrl(),
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        if (! $this->certificate->pdf_key) {
            return [];
        }

        return [
            Attachment::fromStorageDisk('r2', $this->certificate->pdf_key)
                ->as(str_replace('/', '-', $this->certificate->certificate_number).'.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
