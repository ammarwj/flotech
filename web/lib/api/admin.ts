import { apiClient } from "./client";
import type { ActiveSession, ApiEnvelope } from "@/types/api";

// ---- SaaS super admin ----

/** Who is logged in / currently accessing the app. */
export async function getActiveSessions(): Promise<ActiveSession[]> {
  const { data } = await apiClient.get<ApiEnvelope<ActiveSession[]>>("/admin/active-sessions");
  return data.data;
}
