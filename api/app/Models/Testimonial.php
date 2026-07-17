<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Testimonial extends Model
{
    use HasUuids;

    /**
     * Avatar gradients live in the web app (web/lib/landing.ts) so the CSS vars
     * stay in one place; the DB only stores which preset was picked.
     *
     * @var list<string>
     */
    public const PRESETS = ['brand', 'purple', 'pink', 'success', 'amber', 'blue'];

    protected $fillable = [
        'quote',
        'name',
        'role',
        'initials',
        'avatar_preset',
        'rating',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
