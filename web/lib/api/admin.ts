import type { AxiosRequestConfig } from "axios";

import { apiClient } from "./client";
import type {
  ActiveSession,
  AdminStats,
  AdminUser,
  ApiEnvelope,
  AuthUser,
  Paginated,
} from "@/types/api";

// ---- SaaS super admin ----

/** Who is logged in / currently accessing the app. */
export async function getActiveSessions(): Promise<ActiveSession[]> {
  const { data } = await apiClient.get<ApiEnvelope<ActiveSession[]>>("/admin/active-sessions");
  return data.data;
}

/** Isi platform: jumlah turnamen per status, lintas semua organisasi. */
export async function getAdminStats(): Promise<AdminStats> {
  const { data } = await apiClient.get<ApiEnvelope<AdminStats>>("/admin/stats");
  return data.data;
}

// ---- Platform user management ----

export interface AdminUserQuery {
  /**
   * Nama, email, **atau nama event**. Server meng-OR keempat jalan user→event
   * (tim yang dimanajeri, organisasi yang dimiliki, keanggotaan organisasi,
   * penugasan petugas) — lihat `Admin\UserController::orWhereInEvent()`.
   */
  q?: string;
  role?: string;
  /**
   * Jenis akun turunan: `"organizer"` | `"participant"` | `"crew"` (wasit/staf
   * event) | `"none"` (belum ada aktivitas).
   *
   * `"crew"` bukan bagian dari `AdminUser["account_types"]` — penugasan petugas
   * hidup di `officiating`, dan `"none"` di server ikut mengecualikannya, jadi
   * keduanya tidak pernah mengembalikan baris yang sama.
   */
  type?: string;
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

/**
 * Set a user's password for them (support path — no current password needed).
 * All of that user's sessions are revoked server-side. Ordinary users only, the
 * same restriction as impersonation (403 otherwise).
 */
export async function resetAdminUserPassword(
  id: string,
  password: string,
  password_confirmation: string
): Promise<string> {
  const { data } = await apiClient.post<ApiEnvelope<null>>(`/admin/users/${id}/password`, {
    password,
    password_confirmation,
  });
  return data.message;
}
