<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A referee or match official of an event — see the create_event_personnel_table
 * migration for why they are not TeamOfficials.
 */
class EventPersonnel extends Model
{
    use HasUuids;

    /**
     * Laravel would pluralise this to `event_personnels`. "Personnel" is already
     * plural, and the table is not.
     */
    protected $table = 'event_personnel';

    /**
     * The two kinds the organizer asked for. English because this is also the
     * authorization boundary the officiating middleware branches on — one
     * vocabulary across PHP, routes and TypeScript, with nothing to map.
     */
    public const KINDS = ['referee', 'staff'];

    /**
     * Shown on a card when the organizer left role_label empty. Not a default
     * written to the column: an empty label means "no title", and filling it in
     * at write time would make it impossible to tell that apart from someone
     * whose title genuinely is "Wasit".
     */
    public const KIND_LABELS = [
        'referee' => 'Wasit',
        'staff' => 'Staf',
    ];

    protected $fillable = [
        'event_id',
        'full_name',
        'email',
        'user_id',
        'kind',
        'role_label',
        'photo_url',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    /** What goes on the ID card's "sebagai" line. */
    public function roleLabel(): string
    {
        $label = trim((string) $this->role_label);

        return $label !== '' ? $label : (self::KIND_LABELS[$this->kind] ?? 'Petugas');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * The login this row was provisioned. Null means the organizer typed no
     * email, or typed one and the row predates provisioning — either way, no
     * access. Authorization reads this and never `email`: an address is what
     * somebody typed, an account is what they can prove.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
