<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class FeatureDefinition extends Model
{
    use HasUuids;

    protected $fillable = [
        'feature_key',
        'feature_label',
        'feature_group',
        'feature_type',
        'description',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }
}
