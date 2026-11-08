<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An e-BIB design. Twin of IdCardTemplate — see the migration for why it is its
 * own table rather than a `kind` on that one, and for why there is no `bibs`
 * table to go with it.
 */
class BibTemplate extends Model
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
            // renderer divides these by 25.4 and multiplies by the DPI. The
            // note is copied from IdCardTemplate because the trap is: a bib
            // that came out the wrong size has no issued row to catch it.
            'width_mm' => 'float',
            'height_mm' => 'float',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
