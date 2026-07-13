<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Certificate extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'event_id',
        'certificate_template_id',
        'certificate_number',
        'recipient_type',
        'recipient_id',
        'recipient_name',
        'team_name',
        'recipient_email',
        'award_title',
        'pdf_key',
        'issued_at',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(CertificateTemplate::class, 'certificate_template_id');
    }

    /** The URL the QR points at, and the page that proves the document is real. */
    public function verifyUrl(): string
    {
        return rtrim((string) config('certificate.verify_url'), '/').'/'.$this->certificate_number;
    }
}
