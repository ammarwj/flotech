<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One manual point adjustment on a category's table — see the
 * create_standing_adjustments_table migration for why it is a signed ledger
 * row rather than a total per team.
 *
 * Read by StandingService::adjustments(), which sums the rows per team. Nothing
 * else reads a single row except the organizer's ledger dialog.
 */
class StandingAdjustment extends Model
{
    use HasUuids;

    protected $fillable = [
        'category_id',
        'team_id',
        'points',
        'reason',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            // The column is signed and the cast has to keep it that way: the
            // published `adjustment` field is compared against in tests, and a
            // string "-3" from one driver is not the -3 another one returns.
            'points' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(EventCategory::class, 'category_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** Whoever typed this. Named `author` because `createdBy` reads as a column. */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
