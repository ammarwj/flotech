<?php

namespace App\Models;

use App\Services\Catalog;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Event extends Model
{
    use HasUuids;

    /**
     * Which status an event may move to next, keyed by where it is now.
     *
     * Skipping forward is allowed on purpose — a one-day tournament is closed
     * the moment it ends, and nobody wants to march it through every step. Only
     * one move goes backwards (reopening registration); `finished` and
     * `cancelled` are terminal, because closing an event releases the funds the
     * platform was holding and there is no undo for that.
     *
     * @var array<string, array<int, string>>
     */
    public const TRANSITIONS = [
        'draft' => ['open', 'cancelled'],
        'open' => ['registration_closed', 'ongoing', 'finished', 'cancelled'],
        'registration_closed' => ['open', 'ongoing', 'finished', 'cancelled'],
        'ongoing' => ['finished', 'cancelled'],
        'finished' => [],
        'cancelled' => [],
    ];

    protected $fillable = [
        'organization_id',
        'name',
        'slug',
        'sport_type',
        'status',
        'start_date',
        'end_date',
        'timezone',
        'registration_open',
        'registration_close',
        'location_name',
        'location_address',
        'description',
        'banner_url',
        'rules_config',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'registration_open' => 'datetime',
            'registration_close' => 'datetime',
            'rules_config' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The competitions inside this event (U17, U19, Woman, …). Every event has
     * at least one; format, bracket config and fee live on each.
     */
    public function categories(): HasMany
    {
        return $this->hasMany(EventCategory::class)->orderBy('sort_order')->orderBy('created_at');
    }

    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    public function matches(): HasMany
    {
        return $this->hasMany(GameMatch::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(EventPhoto::class)->orderBy('sort_order')->orderBy('created_at');
    }

    public function sponsors(): HasMany
    {
        return $this->hasMany(EventSponsor::class)->orderBy('sort_order')->orderBy('created_at');
    }

    public function ticketCategories(): HasMany
    {
        return $this->hasMany(TicketCategory::class);
    }

    public function ticketOrders(): HasMany
    {
        return $this->hasMany(TicketOrder::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /** Daily public page traffic. Written only by EventViewService. */
    public function viewDaily(): HasMany
    {
        return $this->hasMany(EventViewDaily::class);
    }

    /** Catalog entry for this event's sport (name, colour, scoring, stats). */
    public function sportDefinition(): ?array
    {
        return Catalog::sport($this->sport_type);
    }

    public function isSetBased(): bool
    {
        return Catalog::isSetBased($this->sport_type);
    }

    /**
     * Statuses this event may move to from where it stands.
     *
     * @return array<int, string>
     */
    public function nextStatuses(): array
    {
        return self::TRANSITIONS[$this->status] ?? [];
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, $this->nextStatuses(), true);
    }

    public function isRegistrationOpen(): bool
    {
        if ($this->status !== 'open') {
            return false;
        }

        $now = now();

        return (! $this->registration_open || $this->registration_open->lte($now))
            && (! $this->registration_close || $this->registration_close->gte($now));
    }
}
