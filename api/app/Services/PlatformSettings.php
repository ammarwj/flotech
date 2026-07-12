<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Super-admin-editable platform rules, currently the payout policy.
 *
 * `config/wallet.php` still holds the DEFAULTS — a fresh install works with no
 * rows at all, and a row simply overrides its config counterpart. Read on every
 * wallet request, so it's cached and flushed explicitly on write.
 *
 * Changing a value never rewrites history: every `withdrawals` row snapshots
 * the `minimum_at_request` and `admin_fee` it was created under.
 *
 * NOT settable here: `wallet.timezone`. That decides when an event's day ends,
 * and getting it wrong releases funds mid-event — infrastructure, not policy.
 */
class PlatformSettings
{
    private const KEY = 'platform_settings';

    /**
     * The editable keys, their config default, and the bounds the admin UI and
     * the API both enforce.
     *
     * @var array<string, array{config: string, type: string, min: float, max: float, label: string}>
     */
    public const DEFINITIONS = [
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
    ];

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
            fn () => PlatformSetting::pluck('value', 'key')->all(),
        );
    }

    /** Effective value: the stored override, else the config default. */
    public static function get(string $key): float|int
    {
        $definition = self::DEFINITIONS[$key] ?? null;
        if (! $definition) {
            throw new \InvalidArgumentException("Setting tidak dikenal: {$key}");
        }

        $value = self::overrides()[$key] ?? config($definition['config']);

        return $definition['type'] === 'int' ? (int) $value : (float) $value;
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
     * Persist overrides. Only known keys are written; values are assumed
     * already validated (see UpdatePlatformSettingsRequest).
     *
     * @param  array<string, mixed>  $values
     */
    public static function put(array $values, ?User $actor = null): void
    {
        foreach ($values as $key => $value) {
            if (! isset(self::DEFINITIONS[$key])) {
                continue;
            }

            PlatformSetting::updateOrCreate(
                ['key' => $key],
                ['value' => (string) $value, 'updated_by' => $actor?->id],
            );
        }

        self::flush();
    }

    /** Effective values + bounds, for the admin UI. */
    public static function all(): array
    {
        $out = [];

        foreach (self::DEFINITIONS as $key => $definition) {
            $out[$key] = [
                'key' => $key,
                'label' => $definition['label'],
                'type' => $definition['type'],
                'value' => self::get($key),
                'default' => $definition['type'] === 'int'
                    ? (int) config($definition['config'])
                    : (float) config($definition['config']),
                'min' => $definition['min'],
                'max' => $definition['max'],
                'is_overridden' => array_key_exists($key, self::overrides()),
            ];
        }

        return array_values($out);
    }
}
