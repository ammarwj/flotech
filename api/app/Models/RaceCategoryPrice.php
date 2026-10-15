<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one category costs during one period. The whole early-bird feature is
 * this table.
 *
 * **A missing row means NOT SOLD during that period** — not free, and not
 * inherited. See the migration: this is the one place in this schema where "no
 * value" must not read as "the default value".
 */
class RaceCategoryPrice extends Model
{
    use HasUuids;

    protected $fillable = [
        'race_category_id',
        'race_period_id',
        'price',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(RaceCategory::class, 'race_category_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(RacePeriod::class, 'race_period_id');
    }
}
