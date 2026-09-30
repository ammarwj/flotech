<?php

namespace App\Support;

/**
 * Where an organizer can be paid out. Two kinds live in one table
 * (`bank_accounts`) because a payout destination is one question — who receives
 * the transfer — answered with the same three facts either way: which
 * institution, which number, whose name.
 *
 * `bank_accounts.account_type` says which kind a row is, and the other columns
 * are read accordingly:
 *
 * - `bank`    — `bank_name` is the bank ("BCA"), `account_number` a rekening.
 * - `ewallet` — `bank_name` is the provider key's label ("GoPay"),
 *               `account_number` the registered phone number.
 *
 * Reusing the columns is what keeps `withdrawals`' snapshot, the admin payout
 * queue, the completion emails, and the buyer-facing manual-transfer panel from
 * needing to know this feature exists: they render three strings and a type.
 * Adding a provider is one entry in PROVIDERS plus one in `web/lib/payout.ts`.
 */
class PayoutChannels
{
    public const TYPE_BANK = 'bank';

    public const TYPE_EWALLET = 'ewallet';

    /** @var list<string> */
    public const TYPES = [self::TYPE_BANK, self::TYPE_EWALLET];

    /**
     * E-wallet provider key => display label, which is what gets stored in
     * `bank_name`. The key is never persisted: the label is, so a provider
     * renamed here does not rewrite where past money was sent, exactly like the
     * rest of the withdrawal snapshot.
     *
     * @var array<string, string>
     */
    public const PROVIDERS = [
        'gopay' => 'GoPay',
        'dana' => 'DANA',
        'ovo' => 'OVO',
        'shopeepay' => 'ShopeePay',
        'linkaja' => 'LinkAja',
    ];

    /** @return list<string> */
    public static function providerKeys(): array
    {
        return array_keys(self::PROVIDERS);
    }

    /**
     * Indonesian mobile numbers get typed as "0812…", "+62812…" or "62812…".
     * Normalize to the local `08…` form — that is what an e-wallet app shows
     * the owner, so it is what the admin making the transfer should be
     * comparing against. Non-digits are stripped so a number pasted with
     * spaces or dashes still matches the digits-only column.
     */
    public static function normalizePhone(string $input): string
    {
        $digits = preg_replace('/\D+/', '', $input) ?? '';

        if (str_starts_with($digits, '62')) {
            return '0'.substr($digits, 2);
        }

        if (str_starts_with($digits, '8')) {
            return '0'.$digits;
        }

        return $digits;
    }
}
