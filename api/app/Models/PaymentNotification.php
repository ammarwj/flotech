<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One Midtrans HTTP notification, as received.
 *
 * An audit trail, not a ledger: re-deliveries are separate rows on purpose, so
 * nothing that counts money may read this table. See the migration.
 */
class PaymentNotification extends Model
{
    use HasUuids;

    /**
     * Rows are written once and only ever stamped `handled`/`source_*` by the
     * arm that resolved the order, so there is nothing for an updated_at to
     * tell anyone.
     */
    const UPDATED_AT = null;

    protected $fillable = [
        'midtrans_order_id',
        'source_type',
        'source_id',
        'transaction_id',
        'transaction_status',
        'payment_type',
        'fraud_status',
        'status_code',
        'gross_amount',
        'transaction_time',
        'signature_valid',
        'handled',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'gross_amount' => 'decimal:2',
            'transaction_time' => 'datetime',
            'signature_valid' => 'boolean',
            'handled' => 'boolean',
            'payload' => 'array',
        ];
    }
}
