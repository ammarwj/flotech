import { apiClient } from "./client";
import { downloadBlob, fileNameFromDisposition } from "@/lib/download";
import type {
  ApiEnvelope,
  CheckoutResult,
  DiscountPreview,
  Organization,
  PublicOrganization,
  SocialLinks,
  EventPlanOrder,
  PlanUpgradeOption,
} from "@/types/api";

export async function getOrganizations(): Promise<Organization[]> {
  const { data } = await apiClient.get<ApiEnvelope<Organization[]>>("/organizations");
  return data.data;
}

/** Public organizer profile. 404s for an unknown slug. */
export async function getPublicOrganization(orgSlug: string): Promise<PublicOrganization> {
  const { data } = await apiClient.get<ApiEnvelope<PublicOrganization>>(
    `/public/organizations/${orgSlug}`
  );
  return data.data;
}

export interface CreateOrgPayload {
  name: string;
  description?: string;
  contact_email?: string;
  contact_phone?: string;
  plan_id?: string;
}

export async function createOrganization(payload: CreateOrgPayload): Promise<Organization> {
  const { data } = await apiClient.post<ApiEnvelope<Organization>>("/organizations", payload);
  return data.data;
}

export interface UpdateOrgPayload {
  name?: string;
  slug?: string;
  logo_url?: string | null;
  banner_url?: string | null;
  description?: string | null;
  contact_email?: string | null;
  contact_phone?: string | null;
  /** Handle or full URL per platform; the API normalizes both into a link. */
  social_links?: SocialLinks;
}

export async function updateOrganization(
  orgId: string,
  payload: UpdateOrgPayload
): Promise<Organization> {
  const { data } = await apiClient.patch<ApiEnvelope<Organization>>(
    `/organizations/${orgId}`,
    payload
  );
  return data.data;
}

/**
 * Buy a plan. There is no cycle — one payment covers one event.
 *
 * `paymentChannel` is required exactly when the org's gateway is live (see
 * `org.payment_gateway_enabled`) — ignored on the manual rail.
 */
export async function checkoutPlan(
  orgId: string,
  planId: string,
  paymentChannel?: string,
  discountCode?: string
): Promise<CheckoutResult> {
  const { data } = await apiClient.post<ApiEnvelope<CheckoutResult>>(
    `/organizations/${orgId}/plan-orders/checkout`,
    { plan_id: planId, payment_channel: paymentChannel, discount_code: discountCode }
  );
  return data.data;
}

/**
 * What a discount code does to this plan's price, before buying.
 *
 * Same resolver the checkout runs, so a code accepted here cannot be refused at
 * the till. Refusals come back 422 with a `discount_code` field error — render
 * them inline under the input, not as a toast.
 */
export async function previewDiscount(
  orgId: string,
  planId: string,
  code: string
): Promise<DiscountPreview> {
  const { data } = await apiClient.post<ApiEnvelope<DiscountPreview>>(
    `/organizations/${orgId}/plan-orders/discount-preview`,
    { plan_id: planId, discount_code: code }
  );
  return data.data;
}

/**
 * Every plan order, paid or not. There is deliberately no separate endpoint for
 * unspent credits — filter with `unconsumedOrders()` from lib/plan.ts.
 */
export async function getPlanOrders(orgId: string): Promise<EventPlanOrder[]> {
  const { data } = await apiClient.get<ApiEnvelope<EventPlanOrder[]>>(
    `/organizations/${orgId}/plan-orders`
  );
  return data.data;
}

/** Reopen payment for an unpaid invoice. Returns a fresh Snap transaction. */
export async function payPlanOrder(
  orgId: string,
  orderId: string,
  paymentChannel?: string
): Promise<CheckoutResult> {
  const { data } = await apiClient.post<ApiEnvelope<CheckoutResult>>(
    `/organizations/${orgId}/plan-orders/${orderId}/pay`,
    { payment_channel: paymentChannel }
  );
  return data.data;
}

/**
 * Plans this order may move up to, with the difference each would cost.
 *
 * The server filters the list with the same superset test the checkout enforces,
 * so nothing offered here can be refused at the till. There is no downgrade
 * counterpart — see PlanGate::planCovers().
 */
export async function getPlanUpgradeOptions(
  orgId: string,
  orderId: string
): Promise<PlanUpgradeOption[]> {
  const { data } = await apiClient.get<ApiEnvelope<PlanUpgradeOption[]>>(
    `/organizations/${orgId}/plan-orders/${orderId}/upgrade-options`
  );
  return data.data;
}

/** Raise the top-up bill. Settles like any other plan payment. */
export async function upgradePlanOrder(
  orgId: string,
  orderId: string,
  planId: string,
  paymentChannel?: string
): Promise<CheckoutResult> {
  const { data } = await apiClient.post<ApiEnvelope<CheckoutResult>>(
    `/organizations/${orgId}/plan-orders/${orderId}/upgrade`,
    { plan_id: planId, payment_channel: paymentChannel }
  );
  return data.data;
}

/** The organizer's transfer receipt for a manual plan payment. */
export async function submitPlanOrderProof(
  orgId: string,
  orderId: string,
  proofUrl: string
): Promise<EventPlanOrder> {
  const { data } = await apiClient.post<ApiEnvelope<EventPlanOrder>>(
    `/organizations/${orgId}/plan-orders/${orderId}/proof`,
    { payment_proof_url: proofUrl }
  );
  return data.data;
}

export interface PlanOrderDocument {
  blob: Blob;
  fileName: string;
}

/**
 * Fetch an invoice or receipt PDF.
 *
 * The access token lives in memory, so a plain <a href> to the API would 401 —
 * the request has to go through apiClient and come back as a blob. The blob is
 * what both the preview (an object URL in an iframe) and the download use.
 */
export async function getPlanOrderDocument(
  orgId: string,
  orderId: string,
  kind: "invoice" | "receipt"
): Promise<PlanOrderDocument> {
  const response = await apiClient.get<Blob>(
    `/organizations/${orgId}/plan-orders/${orderId}/${kind}`,
    { responseType: "blob" }
  );

  const fallback = `${kind === "receipt" ? "Kwitansi" : "Invoice"}-${orderId}.pdf`;

  return {
    blob: response.data,
    fileName: fileNameFromDisposition(response.headers["content-disposition"], fallback),
  };
}

export async function downloadPlanOrderDocument(
  orgId: string,
  orderId: string,
  kind: "invoice" | "receipt"
): Promise<void> {
  const { blob, fileName } = await getPlanOrderDocument(orgId, orderId, kind);
  downloadBlob(blob, fileName);
}
