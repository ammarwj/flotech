<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IdCardTemplate extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'name',
        'background_url',
        'width_mm',
        'height_mm',
        'fields',
    ];

    protected function casts(): array
    {
        return [
            'fields' => 'array',
            // float, NOT 'decimal:2'. That cast returns a *string*, and the
            // renderer divides these by 25.4 and multiplies by the DPI. There is
            // no issued-card row to catch a card that came out the wrong size.
            'width_mm' => 'float',
            'height_mm' => 'float',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
