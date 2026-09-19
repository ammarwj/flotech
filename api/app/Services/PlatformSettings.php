<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Super-admin-editable platform rules: the payout policy and the payment rails.
 *
 * `config/wallet.php` and `config/payments.php` still hold the DEFAULTS — a
 * fresh install works with no rows at all, and a row simply overrides its config
 * counterpart. Read on every wallet request, so it's cached and flushed
 * explicitly on write.
 *
 * Changing a value never rewrites history: every `withdrawals` row snapshots
 * the `minimum_at_request` and `admin_fee` it was created under, and every
 * order snapshots the `payment_method` it was created under.
 *
 * NOT settable here: `wallet.timezone`. That decides when an event's day ends,
 * and getting it wrong releases funds mid-event — infrastructure, not policy.
 */
class PlatformSettings
{
    private const KEY = 'platform_settings';

    /**
     * The editable keys, their config default, and the bounds the admin UI and
     * the API both enforce. `min`/`max` are meaningless for `bool` and absent
     * there — anything reading bounds must tolerate null.
     *
     * Static only — the per-channel gateway-fee/PPN toggles below are not here
     * because they cannot be: `config('payment_fees.channels')` is read at
     * runtime, and a PHP `const` must be a compile-time literal. See
     * definitions() for the merged set.
     *
     * @var array<string, array{config: string, type: string, min?: float, max?: float, label: string, description?: string}>
     */
    private const BASE_DEFINITIONS = [
        'payment_gateway_enabled' => [
            'config' => 'payments.gateway_enabled',
            'type' => 'bool',
            'label' => 'Payment gateway aktif',
            'description' => 'Matikan saat Midtrans bermasalah — semua event dipaksa ke transfer manual sampai dinyalakan lagi.',
        ],
        'wallet_minimum_withdrawal' => [
            'config' => 'wallet.minimum_withdrawal',
            'type' => 'money',
            'min' => 0,
            'max' => 100_000_000,
            'label' => 'Minimal penarikan',
        ],
        'wallet_admin_fee' => [
            'config' => 'wallet.admin_fee',
            'type' => 'money',
            'min' => 0,
            'max' => 1_000_000,
            'label' => 'Biaya admin per penarikan',
        ],
        'wallet_hold_days' => [
            'config' => 'wallet.hold_days',
            'type' => 'int',
            'min' => 0,
            'max' => 90,
            'label' => 'Masa tahan setelah event selesai (hari)',
        ],
        // Two margins, not one rate applied twice. The money moves between
        // different parties in each case — a participant paying an organizer,
        // versus an organizer paying us — so they are priced independently and
        // one can be zero while the other is not. PaymentFeeCalculator takes
        // the key as an argument rather than picking it, so a call site that
        // forgets to say which one it is cannot silently inherit the other.
        // Flat rupiah, not a percentage — a fixed cost per transaction rather
        // than one that scales with the ticket/plan price.
        'service_fee_amount' => [
            'config' => 'payments.service_fee_amount',
            'type' => 'money',
            'min' => 0,
            'max' => 100_000,
            'label' => 'Fee platform ke peserta (Rp)',
            'description' => 'Margin platform pada pembayaran peserta ke organizer — tiket dan biaya pendaftaran. Nominal tetap per transaksi, ditambahkan ke tagihan peserta di atas fee gateway.',
        ],
        'plan_service_fee_amount' => [
            'config' => 'payments.plan_service_fee_amount',
            'type' => 'money',
            'min' => 0,
            'max' => 100_000,
            'label' => 'Fee platform ke organizer (Rp)',
            'description' => 'Margin platform pada pembelian paket event oleh organizer. Nominal tetap per transaksi, ditambahkan ke tagihan organizer di atas fee gateway.',
        ],
    ];

    /**
     * BASE_DEFINITIONS plus one gateway-fee/PPN toggle pair per payment
     * channel (`va`, `ewallet`, `retail`, …) — e.g. `gateway_fee_enabled_va`.
     * Channels are independent: turning VA's fee off leaves e-wallet
     * untouched. Underscore, not a dot — Laravel's Validator reads a dotted
     * rule key as a nested-array path, so a flat payload key like
     * `gateway_fee_enabled.va` would silently fail to validate and the
     * toggle would never persist.
     *
     * @return array<string, array{config: string, type: string, min?: float, max?: float, label: string, description?: string}>
     */
    public static function definitions(): array
    {
        $definitions = self::BASE_DEFINITIONS;

        foreach (config('payment_fees.channels') as $key => $channel) {
            $definitions["gateway_fee_enabled_{$key}"] = [
                'config' => "payment_fees.channels.{$key}.fee_enabled",
                'type' => 'bool',
                'label' => "Biaya payment gateway aktif — {$channel['label']}",
                'description' => "Matikan untuk menggratiskan biaya gateway (flat/persen) khusus channel {$channel['label']}. PPN-nya otomatis ikut nol karena dihitung dari biaya ini. Channel lain tidak terpengaruh.",
            ];
            $definitions["ppn_enabled_{$key}"] = [
                'config' => "payment_fees.channels.{$key}.tax_enabled",
                'type' => 'bool',
                'label' => "PPN aktif — {$channel['label']}",
                'description' => "Matikan untuk menggratiskan PPN atas biaya gateway channel {$channel['label']}. Biaya gateway-nya sendiri tidak terpengaruh.",
            ];
        }

        return $definitions;
    }

    /** @var array<string, string>|null in-request memo */
    private static ?array $memo = null;

    public static function flush(): void
    {
        self::$memo = null;
        Cache::forget(self::KEY);
    }

    /** Raw stored overrides, keyed by setting key. */
    public static function overrides(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        return self::$memo = Cache::rememberForever(
            self::KEY,
            fn() => PlatformSetting::pluck('value', 'key')->all(),
        );
    }

    /** Effective value: the stored override, else the config default. */
    public static function get(string $key): float|int|bool
    {
        $definition = self::definitions()[$key] ?? null;
        if (! $definition) {
            throw new \InvalidArgumentException("Setting tidak dikenal: {$key}");
        }

        return self::cast($definition['type'], self::overrides()[$key] ?? config($definition['config']));
    }

    /**
     * Overrides are stored as strings, config defaults are native — so both
     * shapes reach this. `(bool) '0'` is false but `(bool) 'false'` is true,
     * which is why bools go through filter_var rather than a plain cast.
     */
    private static function cast(string $type, mixed $value): float|int|bool
    {
        return match ($type) {
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'int' => (int) $value,
            'percent' => (float) $value,
            default => (float) $value,
        };
    }

    public static function minimumWithdrawal(): float
    {
        return (float) self::get('wallet_minimum_withdrawal');
    }

    public static function adminFee(): float
    {
        return (float) self::get('wallet_admin_fee');
    }

    public static function holdDays(): int
    {
        return (int) self::get('wallet_hold_days');
    }

    /**
     * Whether new payments may go through Midtrans. When false, every
     * organization falls back to manual bank transfer — this is the single
     * question the payment code asks; nothing else reads the setting directly.
     */
    public static function paymentGatewayEnabled(): bool
    {
        return (bool) self::get('payment_gateway_enabled');
    }

    /**
     * Persist overrides. Only known keys are written; values are assumed
     * already validated (see UpdatePlatformSettingsRequest).
     *
     * @param  array<string, mixed>  $values
     */
    public static function put(array $values, ?User $actor = null): void
    {
        foreach ($values as $key => $value) {
            $definition = self::definitions()[$key] ?? null;
            if (! $definition) {
                continue;
            }

            // `(string) false` is '' — store bools as '1'/'0' so the round-trip
            // through the string column stays readable and unambiguous.
            $stored = $definition['type'] === 'bool'
                ? (filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0')
                : (string) $value;

            PlatformSetting::updateOrCreate(
                ['key' => $key],
                ['value' => $stored, 'updated_by' => $actor?->id],
            );
        }

        self::flush();
    }

    /** Effective values + bounds, for the admin UI. */
    public static function all(): array
    {
        $out = [];

        foreach (self::definitions() as $key => $definition) {
            $out[$key] = [
                'key' => $key,
                'label' => $definition['label'],
                'description' => $definition['description'] ?? null,
                'type' => $definition['type'],
                'value' => self::get($key),
                'default' => self::cast($definition['type'], config($definition['config'])),
                'min' => $definition['min'] ?? null,
                'max' => $definition['max'] ?? null,
                'is_overridden' => array_key_exists($key, self::overrides()),
            ];
        }

        return array_values($out);
    }
}
