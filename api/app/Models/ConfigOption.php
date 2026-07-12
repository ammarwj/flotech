<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A reference option the admin can manage: tournament formats, tiebreakers,
 * draw methods, knockout entry rounds, sponsor tiers.
 *
 * `meta` binds the row to code — a format names the engine that runs it, a
 * tiebreaker names its comparator — so a row can never point at logic that
 * doesn't exist. See App\Support\Engines.
 */
class ConfigOption extends Model
{
    use HasUuids;

    public const GROUPS = [
        'tournament_format',
        'tiebreaker',
        'draw_method',
        'knockout_round',
        'sponsor_tier',
    ];

    protected $fillable = [
        'group',
        'key',
        'label',
        'description',
        'meta',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
