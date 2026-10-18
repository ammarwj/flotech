import type {
  EventPlanOrder,
  Plan,
  PlanFeatureDetail,
  PlanSummary,
  SportEvent,
} from "@/types/api";

/**
 * Entitlements belong to an event, not to an organization: a plan is bought once
 * for one event and stays with it. Two events of the same organizer can
 * legitimately answer differently about tickets, certificates or the fee.
 *
 * Everything here mirrors PlanGate on the backend. The reactive net
 * (`isPlanLimitError`) still catches anything these miss, but a gate the user
 * only meets after filling in a whole form is a bad gate.
 */

/**
 * Raw feature value for an event's plan.
 *
 * Three states, and they must stay distinguishable:
 *  - `undefined` — the event isn't loaded yet, so say nothing rather than block;
 *  - `null` — the event has no plan, or the plan lacks the key;
 *  - a string — the value.
 */
function eventValue(
  event: SportEvent | null | undefined,
  key: string
): string | null | undefined {
  if (!event) return undefined;
  return event.plan?.features?.[key] ?? null;
}

export function planAllows(event: SportEvent | null | undefined, key: string): boolean {
  return eventValue(event, key) === "true";
}

/**
 * Numeric cap for an event's plan.
 *
 * `null` = unlimited (`-1`), no cap set, or the event isn't loaded; `0` when the
 * event has no plan at all. That last distinction is the whole point and mirrors
 * PlanGate: an absent value is indistinguishable from "this plan sets no cap",
 * which passes freely — so "no plan" has to be answered before the lookup.
 */
export function planLimit(event: SportEvent | null | undefined, key: string): number | null {
  if (!event) return null;
  if (!event.plan) return 0;

  const raw = event.plan.features?.[key];
  if (raw === undefined || raw === null) return null;

  const limit = Number(raw);
  if (Number.isNaN(limit) || limit < 0) return null; // -1 = unlimited
  return limit;
}

export const isTicketingEnabled = (e?: SportEvent | null) => planAllows(e, "qr_tickets");
export const isCertificateEnabled = (e?: SportEvent | null) =>
  planAllows(e, "certificate_generator");
export const isCertificateEmailEnabled = (e?: SportEvent | null) =>
  planAllows(e, "certificate_email");
export const isIdCardEnabled = (e?: SportEvent | null) =>
  planAllows(e, "id_card_generator");
export const isExportEnabled = (e?: SportEvent | null) => planAllows(e, "export_data");
export const isGalleryEnabled = (e?: SportEvent | null) => planAllows(e, "event_gallery");
export const isSponsorLogosEnabled = (e?: SportEvent | null) => planAllows(e, "sponsor_logos");
export const isOnlineRegistrationEnabled = (e?: SportEvent | null) =>
  planAllows(e, "online_registration");

/**
 * Plan-keyed, unlike its neighbours: the caller is the event form, which gates
 * the payment-method select before the event row exists. Mirrors
 * PlanGate::planAllows(?Plan, …), which exists for the same reason.
 */
export const planAllowsGateway = (p?: PlanSummary | null) =>
  p?.features?.["payment_gateway"] === "true";

export const getCategoryLimit = (e?: SportEvent | null) => planLimit(e, "max_categories");
export const getTeamsPerCategoryLimit = (e?: SportEvent | null) =>
  planLimit(e, "max_teams_per_category");
export const getGalleryLimit = (e?: SportEvent | null) => planLimit(e, "max_gallery_photos");

/**
 * The frontend mirror of PlanGate::orgAllows(), for the three org-level
 * surfaces: certificate templates, ID card templates, and the public-profile
 * hint. Monotone by design — see the backend docblock for why revoking would be
 * worse than being generous, and for why only org-scoped template rows belong
 * here while printing a particular event's cards stays event-keyed.
 */
export function anyEventAllows(
  events: SportEvent[] | null | undefined,
  key: string
): boolean {
  return events?.some((e) => planAllows(e, key)) ?? false;
}

/**
 * Plans the organizer has paid for but not yet spent on an event.
 *
 * This is what stops an abandoned checkout from being money nobody ever sees
 * again — the credit is surfaced on /organizer/billing and on the create-event
 * page rather than sitting invisible in an orders list.
 *
 * An order a paid upgrade has replaced (`superseded`) is *not* one: its
 * entitlement moved to the successor, and counting both would show one purchase
 * twice. Mirrors scopeUnconsumed() on the server, which refuses the same row.
 *
 * A top-up bill (`upgrade_of_id` set) is deliberately **not** excluded here.
 * Once paid it *is* the credit — it is what the old order handed the
 * entitlement to. Filtering on that field looked right and quietly emptied the
 * "Paket siap dipakai" section for anyone who had just upgraded, while the
 * banner beside it — reading the server's own count — still said they held one.
 */
export function unconsumedOrders(orders?: EventPlanOrder[] | null): EventPlanOrder[] {
  return orders?.filter((o) => o.status === "paid" && !o.event_id && !o.superseded) ?? [];
}

/**
 * A paid order whose plan can still be moved up.
 *
 * Being a top-up itself is no bar: climbing twice is allowed, and priced off the
 * whole chain (see paidTowardsPlan on the server).
 */
export function canUpgrade(order?: EventPlanOrder | null): boolean {
  return !!order && order.status === "paid" && !order.superseded;
}

/**
 * What became of a purchase: the event it was spent on, the upgrade that took
 * its place, or nothing yet.
 *
 * The only answer to that question, for the same reason unconsumedOrders() is
 * the only answer to "is this a credit?" — they are the same fact read from the
 * same two fields, and a second reader drifts. It drifted: the history row on
 * /organizer/billing branched on `order.event` alone, so a Pro order that a paid
 * Professional top-up had already replaced read "Belum dipakai" while the credit
 * section above it — which does read `superseded` — correctly left it out. An
 * upgraded order holds `event_id: null` *by design*: the entitlement moved to
 * the successor, which is the row that now names the event.
 *
 * `superseded` is checked after the event, not before: a top-up that has itself
 * been upgraded still names the event it is spent on, and "which event" is the
 * more useful of the two true things.
 */
export type PlanOrderUsage =
  | { kind: "event"; event: { id: string; name: string } }
  | { kind: "superseded" }
  | { kind: "unused" };

export function planOrderUsage(order: EventPlanOrder): PlanOrderUsage {
  if (order.event) return { kind: "event", event: order.event };
  if (order.superseded) return { kind: "superseded" };
  return { kind: "unused" };
}

/**
 * Flat text for the same answer, for the surfaces that cannot link to an event.
 *
 * Kept beside planOrderUsage() rather than inlined at each call site: the wording
 * of "Belum dipakai" is the thing that was wrong in three places at once.
 */
export function planOrderUsageLabel(order: EventPlanOrder): string {
  const usage = planOrderUsage(order);

  if (usage.kind === "event") return usage.event.name;
  if (usage.kind === "superseded") return "Digantikan upgrade";
  return "Belum dipakai";
}

const PLAN_COLORS: Record<string, string> = {
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

/** Human-readable line for a plan feature, e.g. "Kategori: 4" or "Tiket penonton online". */
export function formatPlanFeature(feature: PlanFeatureDetail): string {
  if (feature.value === null || feature.type === "boolean") return feature.label;
  if (feature.type === "numeric" && Number(feature.value) < 0) {
    return `${feature.label}: Unlimited`; // -1 = unlimited
  }
  return `${feature.label}: ${feature.value}`;
}
