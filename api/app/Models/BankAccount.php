<?php

namespace App\Models;

use App\Support\PayoutChannels;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where an organizer gets paid — a bank account or an e-wallet. The table name
 * predates the second kind; `account_type` says which one a row is, and
 * App\Support\PayoutChannels explains how the shared columns are read.
 */
class BankAccount extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'account_type',
        'bank_name',
        'bank_code',
        'account_number',
        'account_holder',
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isEwallet(): bool
    {
        return $this->account_type === PayoutChannels::TYPE_EWALLET;
    }

    /** Last four digits only — the full number is for the paying admin. */
    public function maskedNumber(): string
    {
        $tail = substr($this->account_number, -4);

        return str_repeat('*', max(strlen($this->account_number) - 4, 0)).$tail;
    }
}
