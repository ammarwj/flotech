<?php

namespace App\Models;

use App\Support\SocialPlatforms;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How visitors reach flo-event itself: the contact details and social profiles
 * rendered in the landing footer, plus the platform's own bank account — where
 * an organizer transfers for a plan while the payment gateway is off. Exactly
 * one row — see `current()`.
 */
class SiteSetting extends Model
{
    use HasUuids;

    protected $fillable = [
        'contact_email',
        'contact_phone',
        'sales_email',
        'social_links',
        'bank_name',
        'bank_code',
        'account_number',
        'account_holder',
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
     * Whether a plan can actually be paid for by hand. `bank_code` is optional
     * — it is a convenience for the buyer's banking app, not part of the
     * address — so it deliberately isn't required here.
     */
    public function hasBankAccount(): bool
    {
        return filled($this->bank_name)
            && filled($this->account_number)
            && filled($this->account_holder);
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
