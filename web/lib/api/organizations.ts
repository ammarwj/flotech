import { apiClient } from "./client";
import type { ApiEnvelope, CheckoutResult, Organization } from "@/types/api";

export async function getOrganizations(): Promise<Organization[]> {
  const { data } = await apiClient.get<ApiEnvelope<Organization[]>>("/organizations");
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
