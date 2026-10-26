<?php

namespace App\Models;

use App\Models\Concerns\HasManualPayment;
use App\Support\RegistrationForm;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Team extends Model
{
    use HasManualPayment, HasUuids;

    protected $fillable = [
        'event_id',
        'category_id',
        'name',
        'logo_url',
        'contact_name',
        'contact_phone',
        'custom_fields',
        'status',
        'group_name',
        'seed_pot',
        'registered_at',
        'approved_at',
        'manager_user_id',
        'payment_status',
        'payment_amount',
        // The discount a participant typed, if any. A SNAPSHOT beside the FK,
        // not a lookup through it: an issued invoice must keep reading what was
        // actually charged even after the organizer edits or deletes the code.
        'event_discount_code_id',
        'discount_code',
        'discount_type',
        'discount_value',
        'discount_amount',
        'invoice_number',
        'receipt_number',
        'platform_fee',
        'payment_method',
        'payment_channel',
        // The Midtrans instrument the payment actually settled with. NOT a
        // second spelling of payment_channel above: that is the fee group the
        // buyer picked beforehand, which the gateway fee was computed from.
        'payment_type',
        'gateway_fee',
        'gateway_tax',
        'service_fee',
        'midtrans_order_id',
        'midtrans_token',
        'paid_at',
        'payment_proof_url',
        'payment_proof_uploaded_at',
        'payment_deadline_at',
        'rejected_reason',
        'verified_by',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'custom_fields' => 'array',
            'registered_at' => 'datetime',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
            'payment_amount' => 'decimal:2',
            'platform_fee' => 'decimal:2',
            'discount_value' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'gateway_fee' => 'decimal:2',
            // Part of gateway_fee, not on top of it — never add it into gross.
            'gateway_tax' => 'decimal:2',
            'service_fee' => 'decimal:2',
            'payment_proof_uploaded_at' => 'datetime',
            'payment_deadline_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    protected function paymentStateColumn(): string
    {
        return 'payment_status';
    }

    // Buyer-paid total: what actually gets charged, including fees the
    // organizer never sees. payment_amount stays the registration's own fee.
    public function getGrossAmountAttribute(): float
    {
        return (float) $this->payment_amount + (float) $this->gateway_fee + (float) $this->service_fee;
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(EventCategory::class, 'category_id');
    }

    /**
     * The code row this registration used, for display only.
     *
     * Nullable and `nullOnDelete`: the four snapshot columns are what every
     * reader actually prints, so a deleted code leaves the bill intact.
     */
    public function discountCode(): BelongsTo
    {
        return $this->belongsTo(EventDiscountCode::class, 'event_discount_code_id');
    }

    public function players(): HasMany
    {
        return $this->hasMany(Player::class);
    }

    /**
     * What this entry prints under its name on a fixture card — the club a
     * badminton pair represents, the school a squad is from.
     *
     * The team's own answers and its players' are folded into ONE list, in that
     * order, because that is what the surface is: a card has a line under a
     * name, not a line per person. Tunggal/ganda is the case this exists for and
     * there the club is typed on the player row — `teams` holds a derived name
     * and nothing else (see TeamRosterService::syncPlayers) — while a squad
     * event would ask the team. Reading only one of the two would answer right
     * for half the formats, and a card that quietly prints nothing is the kind
     * of failure the organizer finds out about from a spectator.
     *
     * Deduplicated by RegistrationForm::scheduleTags() per row and again across
     * rows here: both players of a pair naming the same club is one club.
     *
     * Returns [] when the rosters are not loaded rather than querying for them:
     * every fixture reader goes through GameMatch::fixtureRelations(), which
     * loads them only for an event that ticked the flag, and a lazy load here
     * would be 316 silent queries on a 158-fixture category (preventLazyLoading
     * is off, so it would not even throw).
     *
     * @return list<string>
     */
    public function scheduleTags(RegistrationForm $form): array
    {
        $tags = $form->scheduleTags('team', $this->custom_fields);

        if ($this->relationLoaded('players')) {
            foreach ($this->players as $player) {
                $tags = [...$tags, ...$form->scheduleTags('player', $player->custom_fields)];
            }
        }

        return array_values(array_unique($tags));
    }

    /** The bench — pelatih, manajer, ofisial. Never Players; see TeamOfficial. */
    public function officials(): HasMany
    {
        return $this->hasMany(TeamOfficial::class)->orderBy('sort_order');
    }

    /**
     * The team's own documents — a mandate letter, a club deed.
     *
     * Scoped to the rows no player owns, which is what every reader means by
     * "the team's documents": a player's KTP belongs on their roster row, not in
     * a list rendered under the team's name. The unscoped set still exists on
     * the table and is what MediaCleanupService sweeps by team_id, so both
     * kinds are cleaned up from the team alone.
     */
    public function documents(): HasMany
    {
        return $this->hasMany(RegistrationDocument::class)->whereNull('player_id')->whereNull('official_id');
    }

    /**
     * Both kinds at once. Only the pruning side of TeamRosterService wants this:
     * a file that moved from a team slot to a player slot in one request is not
     * stale, and reading only one scope would delete the file just saved.
     */
    public function allDocuments(): HasMany
    {
        return $this->hasMany(RegistrationDocument::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }
}
