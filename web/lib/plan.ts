import type { Organization, Plan, PlanFeatureDetail, SportEvent } from "@/types/api";

/**
 * Active-event cap for an org's plan, mirroring the backend `max_active_events`
 * limit. Returns `null` when unlimited (`-1`) or undefined (no limit set).
 */
export function getActiveEventLimit(org?: Organization | null): number | null {
  const raw = org?.plan?.features?.max_active_events;
  if (raw === undefined || raw === null) return null;

  const limit = Number(raw);
  if (Number.isNaN(limit) || limit < 0) return null; // -1 = unlimited
  return limit;
}

const PLAN_COLORS: Record<string, string> = {
  free: "var(--plan-free)",
  starter: "var(--plan-starter)",
  pro: "var(--plan-pro)",
  professional: "var(--plan-professional)",
};

/** Swatch colour for a plan, shared by the landing table and the dashboard cards. */
export function getPlanColor(slug: string): string {
  return PLAN_COLORS[slug] ?? "var(--brand-600)";
}

/** Raw feature value from a plan's `features` map, or null when the plan lacks it. */
export function getPlanFeatureValue(plan: Plan, key: string): string | null {
  return plan.features?.[key] ?? null;
}

/** Human-readable line for a plan feature, e.g. "Event aktif: 3" or "Tiket QR". */
export function formatPlanFeature(feature: PlanFeatureDetail): string {
  if (feature.value === null || feature.type === "boolean") return feature.label;
  if (feature.type === "numeric" && Number(feature.value) < 0) {
    return `${feature.label}: Unlimited`; // -1 = unlimited
  }
  return `${feature.label}: ${feature.value}`;
}

/**
 * Whole-percent yearly discount, or 0 when the plan is free / undiscounted.
 * Free plans are excluded so they never render a "save 20%" badge on Rp 0.
 */
export function getYearlyDiscount(plan: Plan): number {
  if (plan.price_monthly <= 0) return 0;
  return Math.round(plan.yearly_discount_percent ?? 0);
}

/**
 * Mirrors Plan::computeYearlyPrice() on the backend, for previewing the result in
 * the admin editor before saving. The server recomputes it on write and stays the
 * source of truth — never send the result of this back as a price.
 */
export function computeYearlyPrice(monthly: number, discountPercent: number): number {
  return Math.round((monthly * 12 * (1 - discountPercent / 100)) / 1000) * 1000;
}

/**
 * Per-month figure for a yearly subscription. Both pricing tables advertise the
 * monthly rate and disclose the yearly sum separately, so plans stay comparable
 * across billing cycles — `price_yearly` is still what actually gets billed.
 */
export function getMonthlyEquivalent(plan: Plan): number {
  return plan.price_yearly / 12;
}

/** Best discount across plans, for the billing-cycle toggle badge. */
export function getMaxYearlyDiscount(plans?: Plan[] | null): number {
  return plans?.reduce((best, plan) => Math.max(best, getYearlyDiscount(plan)), 0) ?? 0;
}

/** An event counts against the limit unless it's finished or cancelled. */
export function isActiveEvent(event: Pick<SportEvent, "status">): boolean {
  return event.status !== "finished" && event.status !== "cancelled";
}

export function countActiveEvents(events?: SportEvent[] | null): number {
  return events?.filter(isActiveEvent).length ?? 0;
}

/** Whether creating another event would exceed the plan's active-event cap. */
export function isActiveEventLimitReached(
  org?: Organization | null,
  events?: SportEvent[] | null
): boolean {
  const limit = getActiveEventLimit(org);
  if (limit === null) return false;
  return countActiveEvents(events) >= limit;
}

/** Whether the org's plan includes the QR ticketing feature (`qr_tickets`). */
export function isTicketingEnabled(org?: Organization | null): boolean {
  return org?.plan?.features?.qr_tickets === "true";
}

/** Whether the org's plan includes the certificate generator. */
export function isCertificateEnabled(org?: Organization | null): boolean {
  return org?.plan?.features?.certificate_generator === "true";
}

/** Whether the org's plan can email issued certificates to their recipients. */
export function isCertificateEmailEnabled(org?: Organization | null): boolean {
  return org?.plan?.features?.certificate_email === "true";
}

/**
 * Total-tickets-per-event cap for an org's plan (`max_tickets_per_event`).
 * Returns `null` when unlimited (`-1`) or undefined.
 */
export function getTicketLimit(org?: Organization | null): number | null {
  const raw = org?.plan?.features?.max_tickets_per_event;
  if (raw === undefined || raw === null) return null;

  const limit = Number(raw);
  if (Number.isNaN(limit) || limit < 0) return null; // -1 = unlimited
  return limit;
}
