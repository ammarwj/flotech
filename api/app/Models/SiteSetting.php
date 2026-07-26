<?php

namespace App\Models;

use App\Support\SocialPlatforms;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How visitors reach flo-event itself: the contact details and social profiles
 * rendered in the landing footer. Exactly one row — see `current()`.
 */
class SiteSetting extends Model
{
    use HasUuids;

    protected $fillable = [
        'contact_email',
        'contact_phone',
        'sales_email',
        'social_links',
    ];

    protected function casts(): array
    {
        return [
            'social_links' => 'array',
        ];
    }

    /**
     * The one row, or an unsaved instance when nobody has filled anything in.
     * Returning a model rather than creating one keeps the public endpoint a
     * pure read — it is hit by every visitor on six pages.
     */
    public static function current(): self
    {
        return static::query()->first() ?? new static;
    }

    /**
     * @return array<string, string|null>
     */
    public function socialLinksMap(): array
    {
        return SocialPlatforms::map($this->social_links);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
