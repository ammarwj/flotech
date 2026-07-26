<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One role a team official of this sport can hold (Pelatih Kepala, Manajer
 * Tim…). The key is what team_officials.role stores; the label is only how it's
 * shown, so renaming it in the admin panel reaches every team ever entered.
 */
class SportOfficialRole extends Model
{
    use HasUuids;

    protected $fillable = [
        'sport_id',
        'role_key',
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
