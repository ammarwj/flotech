import type { AxiosRequestConfig } from "axios";

import { apiClient } from "./client";
import type { ActiveSession, AdminUser, ApiEnvelope, AuthUser, Paginated } from "@/types/api";

// ---- SaaS super admin ----

/** Who is logged in / currently accessing the app. */
export async function getActiveSessions(): Promise<ActiveSession[]> {
  const { data } = await apiClient.get<ApiEnvelope<ActiveSession[]>>("/admin/active-sessions");
  return data.data;
}

// ---- Platform user management ----

export interface AdminUserQuery {
  q?: string;
  role?: string;
  page?: number;
  per_page?: number;
}

/** Paginated, searchable list of all platform users. */
export async function getAdminUsers(params: AdminUserQuery = {}): Promise<Paginated<AdminUser>> {
  const { data } = await apiClient.get<ApiEnvelope<Paginated<AdminUser>>>("/admin/users", {
    params,
  });
  return data.data;
}

export interface AdminUserUpdate {
  role?: "super_admin" | "user";
  is_verified?: boolean;
}

export async function updateAdminUser(id: string, payload: AdminUserUpdate): Promise<AdminUser> {
  const { data } = await apiClient.patch<ApiEnvelope<AdminUser>>(`/admin/users/${id}`, payload);
  return data.data;
}

export async function deleteAdminUser(id: string): Promise<void> {
  await apiClient.delete(`/admin/users/${id}`);
}

/**
 * "Login as" this user. Returns an access token that acts as them; the admin's
 * own refresh cookie is left in place, so `refreshAccessToken()` brings the
 * admin back. Super admins can only impersonate ordinary users (403 otherwise).
 *
 * `config` lets AuthGate pass the admin's token explicitly when re-entering
 * impersonation on boot, while the store is still empty (the request
 * interceptor only overrides Authorization when the store holds a token).
 */
export async function impersonateAdminUser(
  id: string,
  config?: AxiosRequestConfig
): Promise<{ access_token: string; user: AuthUser }> {
  const { data } = await apiClient.post<
    ApiEnvelope<{ access_token: string; user: AuthUser }>
  >(`/admin/users/${id}/impersonate`, undefined, config);
  return data.data;
}
