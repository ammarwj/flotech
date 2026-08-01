<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'price',
        'is_active',
        'is_public',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function features(): HasMany
    {
        return $this->hasMany(PlanFeature::class);
    }

    /**
     * The events running on this plan.
     *
     * Load-bearing for Admin\PlanController::destroy(), which refuses to delete
     * a plan that events still point at: the foreign key is nullOnDelete, so
     * deleting one would silently strip entitlements off live tournaments.
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }
}
