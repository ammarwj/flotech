<?php

namespace App\Services;

use App\Models\Organization;

/**
 * Resolves plan feature flags and numeric limits for an organization.
 * Backs the CheckPlanFeature / CheckPlanLimit middleware.
 */
class PlanGate
{
    /**
     * Raw feature value for an org's plan, or null when unset / no plan.
     */
    public function value(Organization $org, string $featureKey): ?string
    {
        $plan = $org->plan;
        if (! $plan) {
            return null;
        }

        return $plan->features->firstWhere('feature_key', $featureKey)?->value;
    }

    /**
     * Whether a boolean feature is enabled for the org.
     */
    public function allows(Organization $org, string $featureKey): bool
    {
        return $this->value($org, $featureKey) === 'true';
    }

    /**
     * Numeric limit for a feature. -1 means unlimited; null means undefined.
     */
    public function limit(Organization $org, string $featureKey): ?int
    {
        $value = $this->value($org, $featureKey);

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Whether adding more would stay within the plan limit.
     * Unlimited (-1) and undefined limits always pass.
     */
    public function withinLimit(Organization $org, string $featureKey, int $currentCount): bool
    {
        $limit = $this->limit($org, $featureKey);

        if ($limit === null || $limit === -1) {
            return true;
        }

        return $currentCount < $limit;
    }
}
