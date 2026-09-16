<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One team's sheet for one fixture: who starts, who is on the bench, who sits
 * with them — submitted by the manager, approved by the referee.
 *
 * See the create_match_lineup_tables migration for why players and officials are
 * two tables and not one.
 */
class MatchLineup extends Model
{
    use HasUuids;

    public const STATUSES = ['draft', 'submitted', 'approved', 'rejected'];

    /**
     * Human copy stays Indonesian while the stored value stays English — the same
     * split EventPersonnel::KIND_LABELS makes, for the same reason.
     */
    public const STATUS_LABELS = [
        'draft' => 'Draf',
        'submitted' => 'Menunggu acc wasit',
        'approved' => 'Disetujui wasit',
        'rejected' => 'Ditolak wasit',
    ];

    protected $fillable = [
        'match_id',
        'team_id',
        'status',
        'submitted_at',
        'submitted_by',
        'reviewed_at',
        'reviewed_by',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * Whether the manager may still change it.
     *
     * The lock is the point of the whole feature: a sheet that can be edited after
     * it was approved makes the approval a decoration. `rejected` is editable
     * again on purpose — that is what the referee sent it back for.
     */
    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'rejected'], true);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(GameMatch::class, 'match_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** Named players, starters first, then the order the manager typed. */
    public function players(): HasMany
    {
        return $this->hasMany(MatchLineupPlayer::class, 'lineup_id')->orderBy('sort_order');
    }

    /** The bench staff sitting with them — never Players; see TeamOfficial. */
    public function officials(): HasMany
    {
        return $this->hasMany(MatchLineupOfficial::class, 'lineup_id')->orderBy('sort_order');
    }

    /** The manager's participant account, not an event personnel row. */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /** The referee, as an assignment to this event — not as an account. */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(EventPersonnel::class, 'reviewed_by');
    }
}
