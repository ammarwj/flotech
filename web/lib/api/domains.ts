import { apiClient } from "./client";
import type {
  ApiEnvelope,
  CheckoutResult,
  DomainAddonKind,
  DomainAddonPreview,
  DomainAddonQuote,
  EventPlanOrder,
} from "@/types/api";

/**
 * The domain add-on: price list, preview, buy-later checkout, and the super
 * admin's registration queue.
 *
 * The add-on bought *alongside* a plan is not here — it rides on
 * `checkoutPlan()` in `organizations.ts`, because it is the same purchase and
 * the same invoice. This file holds what the add-on has of its own.
 */

/** Public price list. Quoted on the pricing page, before anyone logs in. */
export async function getDomainAddons(): Promise<DomainAddonQuote> {
  const { data } = await apiClient.get<ApiEnvelope<DomainAddonQuote>>("/domain-addons");
  return data.data;
}

/**
 * What an add-on would cost, and whether the name can be had.
 *
 * Same resolver the checkout runs, so nothing accepted here is refused at the
 * till — and the `amount` it returns is what must be handed to ChannelPicker.
 * Refusals come back 422 on `domain_name`; render them inline under the input.
 */
export async function previewDomainAddon(
  orgId: string,
  kind: DomainAddonKind,
  name?: string
): Promise<DomainAddonPreview> {
  const { data } = await apiClient.post<ApiEnvelope<DomainAddonPreview>>(
    `/organizations/${orgId}/plan-orders/domain-preview`,
    { domain_kind: kind, domain_name: name }
  );
  return data.data;
}

/**
 * Buy a domain for an event that already exists.
 *
 * Returns the same payment shape as every other checkout — read it through
 * `checkoutOutcome()` rather than branching on `redirect_url`.
 */
export async function checkoutDomainAddon(
  orgId: string,
  eventId: string,
  kind: DomainAddonKind,
  name?: string,
  paymentChannel?: string
): Promise<CheckoutResult> {
  const { data } = await apiClient.post<ApiEnvelope<CheckoutResult>>(
    `/organizations/${orgId}/plan-orders/domain-checkout`,
    {
      event_id: eventId,
      domain_kind: kind,
      domain_name: name,
      payment_channel: paymentChannel,
    }
  );
  return data.data;
}

// ---- SaaS admin ----

export interface DomainOrderQueue {
  /** Paid, name not registered yet. Oldest first: these people are waiting. */
  pending: EventPlanOrder[];
  /** Already registered, newest first. Capped at 50. */
  fulfilled: EventPlanOrder[];
}

export async function getDomainOrders(): Promise<DomainOrderQueue> {
  const { data } = await apiClient.get<ApiEnvelope<DomainOrderQueue>>("/admin/domain-orders");
  return data.data;
}

/**
 * Record the registered name — which also attaches it to the event.
 *
 * `domainName` may differ from what the organizer asked for (theirs was taken,
 * or they left the choice to us); `note` is the only thing on their screen that
 * explains the difference.
 */
export async function fulfilDomainOrder(
  orderId: string,
  domainName: string,
  note?: string
): Promise<EventPlanOrder> {
  const { data } = await apiClient.post<ApiEnvelope<EventPlanOrder>>(
    `/admin/domain-orders/${orderId}/fulfil`,
    { domain_name: domainName, domain_note: note }
  );
  return data.data;
}
