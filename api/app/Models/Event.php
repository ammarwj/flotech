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

    protected $fillable = [
        'organization_id',
        'name',
        'slug',
        'sport_type',
        'tournament_format',
        'status',
        'start_date',
        'end_date',
        'registration_open',
        'registration_close',
        'location_name',
        'location_address',
        'description',
        'banner_url',
        'max_teams',
        'registration_fee',
        'rules_config',
        'bracket_config',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'registration_open' => 'datetime',
            'registration_close' => 'datetime',
            'registration_fee' => 'decimal:2',
            'max_teams' => 'integer',
            'rules_config' => 'array',
            'bracket_config' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
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

    /**
     * The engine that runs this event's format. A format is a preset — several
     * may share one engine ("Liga" and "Liga 2 Putaran" are both `league`), so
     * scheduling and standings branch on this, never on `tournament_format`.
     */
    public function engine(): ?string
    {
        return Catalog::engineOf($this->tournament_format);
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
