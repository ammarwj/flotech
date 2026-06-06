import { apiClient } from "./client";
import type { ApiEnvelope, FeatureDefinition, Plan } from "@/types/api";

export async function getPublicPlans(): Promise<Plan[]> {
  const { data } = await apiClient.get<ApiEnvelope<Plan[]>>("/plans");
  return data.data;
}

// ---- SaaS admin ----

export async function getAdminPlans(): Promise<Plan[]> {
  const { data } = await apiClient.get<ApiEnvelope<Plan[]>>("/admin/plans");
  return data.data;
}

export type PlanInput = Omit<Plan, "id" | "features">;

export async function createPlan(payload: Partial<PlanInput>): Promise<Plan> {
  const { data } = await apiClient.post<ApiEnvelope<Plan>>("/admin/plans", payload);
  return data.data;
}

export async function updatePlan(id: string, payload: Partial<PlanInput>): Promise<Plan> {
  const { data } = await apiClient.put<ApiEnvelope<Plan>>(`/admin/plans/${id}`, payload);
  return data.data;
}

export async function deletePlan(id: string): Promise<void> {
  await apiClient.delete(`/admin/plans/${id}`);
}

export async function syncPlanFeatures(
  id: string,
  features: Record<string, string>
): Promise<Plan> {
  const { data } = await apiClient.put<ApiEnvelope<Plan>>(`/admin/plans/${id}/features`, {
    features,
  });
  return data.data;
}

export async function getFeatureDefinitions(): Promise<FeatureDefinition[]> {
  const { data } = await apiClient.get<ApiEnvelope<FeatureDefinition[]>>(
    "/admin/feature-definitions"
  );
  return data.data;
}
