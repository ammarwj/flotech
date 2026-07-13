import { apiClient } from "./client";
import { downloadBlob, fileNameFromDisposition } from "@/lib/download";
import type {
  ApiEnvelope,
  CheckoutResult,
  Organization,
  PublicOrganization,
  SocialLinks,
  Subscription,
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

export async function checkoutSubscription(
  orgId: string,
  planId: string,
  billingCycle: "monthly" | "yearly"
): Promise<CheckoutResult> {
  const { data } = await apiClient.post<ApiEnvelope<CheckoutResult>>(
    `/organizations/${orgId}/subscriptions/checkout`,
    { plan_id: planId, billing_cycle: billingCycle }
  );
  return data.data;
}

export async function getSubscriptions(orgId: string): Promise<Subscription[]> {
  const { data } = await apiClient.get<ApiEnvelope<Subscription[]>>(
    `/organizations/${orgId}/subscriptions`
  );
  return data.data;
}

/** Reopen payment for an unpaid invoice. Returns a fresh Snap transaction. */
export async function paySubscription(orgId: string, subId: string): Promise<CheckoutResult> {
  const { data } = await apiClient.post<ApiEnvelope<CheckoutResult>>(
    `/organizations/${orgId}/subscriptions/${subId}/pay`
  );
  return data.data;
}

export interface SubscriptionDocument {
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
export async function getSubscriptionDocument(
  orgId: string,
  subId: string,
  kind: "invoice" | "receipt"
): Promise<SubscriptionDocument> {
  const response = await apiClient.get<Blob>(
    `/organizations/${orgId}/subscriptions/${subId}/${kind}`,
    { responseType: "blob" }
  );

  const fallback = `${kind === "receipt" ? "Kwitansi" : "Invoice"}-${subId}.pdf`;

  return {
    blob: response.data,
    fileName: fileNameFromDisposition(response.headers["content-disposition"], fallback),
  };
}

export async function downloadSubscriptionDocument(
  orgId: string,
  subId: string,
  kind: "invoice" | "receipt"
): Promise<void> {
  const { blob, fileName } = await getSubscriptionDocument(orgId, subId, kind);
  downloadBlob(blob, fileName);
}
