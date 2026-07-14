import { apiClient } from "./client";
import type { DashboardMode } from "@/lib/hooks/use-dashboard-mode";
import type { ApiEnvelope, AuthTokenResponse, AuthUser } from "@/types/api";

export interface RegisterPayload {
  full_name: string;
  email: string;
  phone?: string;
  password: string;
  password_confirmation: string;
  /** Which dashboard the account starts (and keeps landing) in. */
  default_mode?: DashboardMode;
}

export async function register(payload: RegisterPayload): Promise<AuthTokenResponse> {
  const { data } = await apiClient.post<ApiEnvelope<AuthTokenResponse>>("/auth/register", payload);
  return data.data;
}

export async function login(email: string, password: string): Promise<AuthTokenResponse> {
  const { data } = await apiClient.post<ApiEnvelope<AuthTokenResponse>>("/auth/login", {
    email,
    password,
  });
  return data.data;
}

export async function logout(): Promise<void> {
  await apiClient.post("/auth/logout");
}

export async function me(): Promise<AuthUser> {
  const { data } = await apiClient.get<ApiEnvelope<AuthUser>>("/auth/me");
  return data.data;
}

/** Remembers the dashboard the next login should open in. */
export async function updateDefaultMode(
  default_mode: AuthUser["default_mode"]
): Promise<AuthUser> {
  const { data } = await apiClient.patch<ApiEnvelope<AuthUser>>("/auth/preferences", {
    default_mode,
  });
  return data.data;
}

export async function forgotPassword(email: string): Promise<string> {
  const { data } = await apiClient.post<ApiEnvelope<null>>("/auth/forgot-password", { email });
  return data.message;
}

export interface ResetPasswordPayload {
  token: string;
  email: string;
  password: string;
  password_confirmation: string;
}

export async function resetPassword(payload: ResetPasswordPayload): Promise<string> {
  const { data } = await apiClient.post<ApiEnvelope<null>>("/auth/reset-password", payload);
  return data.message;
}
