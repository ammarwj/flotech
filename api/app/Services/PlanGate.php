<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Organization;
use App\Models\Plan;

/**
 * Resolves plan feature flags and numeric limits for an *event*.
 *
 * The plan belongs to the event, not to the organization: it is bought once,
 * for one event, and stays with it. Two events of the same organizer can
 * legitimately answer differently about tickets, certificates or the platform
 * fee, and every gate in the application has to be able to say so.
 */
class PlanGate
{
    /**
     * planId => [feature_key => value].
     *
     * `$plan->features` is a lazy relation and gating runs inside loops — once
     * per category, once per photo batch, once per fee. Memoized by plan id
     * rather than by event because many events share one plan and the answer is
     * identical. Same shape as Catalog and PlanResource::definitions().
     *
     * @var array<string, array<string, string>>
     */
    private static array $memo = [];

    public function value(Event $event, string $featureKey): ?string
    {
        return $this->planValue($event->plan, $featureKey);
    }

    public function allows(Event $event, string $featureKey): bool
    {
        return $this->planAllows($event->plan, $featureKey);
    }

    public function limit(Event $event, string $featureKey): ?int
    {
        return $this->planLimit($event->plan, $featureKey);
    }

    /**
     * Whether adding `$adding` more would stay inside the cap.
     *
     * `$adding` exists because two callers ask about a whole list at once
     * (syncCategories, storePhotos) rather than about one more row. It defaults
     * to 1, so every call site that asks "may I add one?" reads unchanged.
     */
    public function withinLimit(Event $event, string $featureKey, int $currentCount, int $adding = 1): bool
    {
        return $this->planWithinLimit($event->plan, $featureKey, $currentCount, $adding);
    }

    /**
     * The plan-keyed core.
     *
     * Exposed separately because EventController::store() has to gate before
     * the event row exists — it is spending a credit whose plan it already
     * knows, and the categories in the same request are checked against it.
     */
    public function planValue(?Plan $plan, string $featureKey): ?string
    {
        if (! $plan) {
            return null;
        }

        self::$memo[$plan->id] ??= $plan->features()->pluck('value', 'feature_key')->all();

        return self::$memo[$plan->id][$featureKey] ?? null;
    }

    public function planAllows(?Plan $plan, string $featureKey): bool
    {
        return $this->planValue($plan, $featureKey) === 'true';
    }

    /** -1 means unlimited; null means the plan defines no such limit. */
    public function planLimit(?Plan $plan, string $featureKey): ?int
    {
        $value = $this->planValue($plan, $featureKey);

        return is_numeric($value) ? (int) $value : null;
    }

    public function planWithinLimit(?Plan $plan, string $featureKey, int $currentCount, int $adding = 1): bool
    {
        // No plan means no entitlements at all — an event has to be paid for
        // before it can do anything. This must be caught *before* consulting the
        // limit: an event without a plan has no feature values, and an absent
        // value is indistinguishable from "this plan sets no cap", which passes
        // freely. Without this an unpaid event would get unlimited everything.
        if (! $plan) {
            return false;
        }

        $limit = $this->planLimit($plan, $featureKey);

        if ($limit === null || $limit === -1) {
            return true;
        }

        return $currentCount + $adding <= $limit;
    }

    /**
     * The one org-level question left, for the two things an organization owns
     * rather than an event: its public profile, and its certificate templates
     * (org-scoped rows reused across events).
     *
     * Deliberately monotone — once an organization has run one event on a plan
     * carrying the feature, it keeps it. Revoking it when that tournament ends
     * would 404 a profile that is already indexed and linked from every past
     * event page, which is worse than a slightly generous grant.
     *
     * Everything else must go through the event-keyed methods above. A third
     * caller appearing here is a sign that feature is really per-event.
     *
     * Deliberately not memoized, unlike planValue() above. This has exactly two
     * callers and each asks once per request, so a cache would save nothing —
     * while making the answer go stale the moment an event is created in the
     * same request. One EXISTS query is the cheaper trade.
     */
    public function orgAllows(Organization $org, string $featureKey): bool
    {
        return $org->events()
            ->whereHas('plan.features', fn ($q) => $q->where('feature_key', $featureKey)->where('value', 'true'))
            ->exists();
    }

    /**
     * Whether `$target` grants everything `$current` does — the test that makes
     * an upgrade an upgrade.
     *
     * Deliberately not a price comparison. The catalogue is editable by a super
     * admin at /admin/plans, so a dearer plan can perfectly well be missing a
     * feature, and "upgrading" an event that already holds 15 gallery photos
     * onto a plan without `event_gallery` would strip something it is actively
     * using — the same class of harm `participant_type` is locked to prevent.
     * Comparing the feature maps refuses that case for free, and refusing every
     * non-superset is also exactly what makes a *downgrade* impossible: there is
     * no separate rule to remember to keep in step.
     *
     * Booleans must not go true → false. Numbers must not shrink, with `-1`
     * (unlimited) treated as the largest value rather than as less than 1. A key
     * the current plan does not carry places no obligation on the target.
     */
    public function planCovers(?Plan $current, Plan $target): bool
    {
        if (! $current) {
            return true;
        }

        self::$memo[$current->id] ??= $current->features()->pluck('value', 'feature_key')->all();
        self::$memo[$target->id] ??= $target->features()->pluck('value', 'feature_key')->all();

        foreach (self::$memo[$current->id] as $key => $value) {
            $theirs = self::$memo[$target->id][$key] ?? null;

            if (is_numeric($value)) {
                // Absent means "no cap named", which is not a promise of more —
                // for a key the current plan does bound, treat it as zero.
                $mine = (int) $value;
                $other = is_numeric($theirs) ? (int) $theirs : 0;

                if ($mine === -1) {
                    if ($other !== -1) {
                        return false;
                    }

                    continue;
                }

                if ($other !== -1 && $other < $mine) {
                    return false;
                }

                continue;
            }

            if ($value === 'true' && $theirs !== 'true') {
                return false;
            }
        }

        return true;
    }

    /** Drop the per-request memo. A fresh database per test needs it. */
    public static function flush(): void
    {
        self::$memo = [];
    }
}
