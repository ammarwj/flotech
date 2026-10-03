<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One super-admin override of a landing "Proof" counter.
 *
 * A null column means "inherit App\Support\LandingMetrics" — see the migration.
 * Written exclusively by LandingStatService::put(), which also deletes a row
 * that has gone back to matching the catalog on all three columns.
 */
class LandingStatSetting extends Model
{
    use HasUuids;

    protected $fillable = ['metric_key', 'label', 'is_active', 'sort_order', 'updated_by'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
