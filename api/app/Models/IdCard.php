<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One issued ID card — the row that makes a card's QR point at something.
 *
 * Twin of Certificate, including why every descriptive column here is a
 * snapshot: see the create_id_cards_table migration.
 */
class IdCard extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'event_id',
        'id_card_template_id',
        'card_number',
        'recipient_type',
        'recipient_id',
        'recipient_name',
        'role_label',
        'team_name',
        'recipient_email',
        'png_key',
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
        return $this->belongsTo(IdCardTemplate::class, 'id_card_template_id');
    }

    /**
     * The URL the printed QR opens, and the page that proves the card is real.
     *
     * Read off config, never built from a literal: the host differs per
     * environment and the path is also a Next route. Same shape as
     * Certificate::verifyUrl().
     */
    public function verifyUrl(): string
    {
        return rtrim((string) config('id_card.verify_url'), '/').'/'.$this->card_number;
    }

    /** The filename this card's PNG is attached and downloaded under. */
    public function filename(): string
    {
        return str_replace('/', '-', (string) $this->card_number).'.png';
    }
}
