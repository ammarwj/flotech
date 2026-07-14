<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One position a player of this sport can hold (Kiper, Pivot, Libero…). The
 * key is what players.position stores; the label is only how it's shown, so
 * renaming it in the admin panel reaches every roster ever entered.
 */
class SportPosition extends Model
{
    use HasUuids;

    protected $fillable = [
        'sport_id',
        'position_key',
        'label',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function sport(): BelongsTo
    {
        return $this->belongsTo(Sport::class);
    }
}
