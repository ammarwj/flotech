<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankAccount extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id',
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

    /** Last four digits only — the full number is for the paying admin. */
    public function maskedNumber(): string
    {
        $tail = substr($this->account_number, -4);

        return str_repeat('*', max(strlen($this->account_number) - 4, 0)).$tail;
    }
}
