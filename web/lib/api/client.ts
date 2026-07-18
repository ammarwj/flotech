import axios, {
  AxiosError,
  type AxiosRequestConfig,
  type InternalAxiosRequestConfig,
} from "axios";

import { useAuthStore } from "@/stores/auth-store";

const API_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000/api/v1";

export const apiClient = axios.create({
  baseURL: API_URL,
  withCredentials: true, // send/receive the HttpOnly refresh cookie
  headers: { Accept: "application/json" },
});

// ---- Attach the in-memory access token to every request ----
apiClient.interceptors.request.use((config: InternalAxiosRequestConfig) => {
  const token = useAuthStore.getState().accessToken;
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

// ---- Auto-refresh on 401 (single-flight) ----
type RetryConfig = AxiosRequestConfig & { _retry?: boolean };

let refreshPromise: Promise<string | null> | null = null;

async function requestRefresh(): Promise<string | null> {
  try {
    // Calls the Next.js route which forwards the HttpOnly cookie to Laravel.
    const res = await fetch("/api/auth/refresh", { method: "POST" });
    if (!res.ok) return null;
    const data = (await res.json()) as { accessToken?: string };
    return data.accessToken ?? null;
  } catch {
    return null;
  }
}

/**
 * Single-flight refresh: exchanges the HttpOnly refresh cookie for a new access
 * token. Concurrent callers (the 401 interceptor and the auth bootstrap) share
 * one in-flight request. Returns null when the session can't be refreshed.
 */
export function refreshAccessToken(): Promise<string | null> {
  refreshPromise ??= requestRefresh().finally(() => {
    refreshPromise = null;
  });
  return refreshPromise;
}

apiClient.interceptors.response.use(
  (response) => response,
  async (error: AxiosError) => {
    const original = error.config as RetryConfig | undefined;

    if (error.response?.status === 401 && original && !original._retry) {
      original._retry = true;

      const wasImpersonating = useAuthStore.getState().impersonating;
      const newToken = await refreshAccessToken();

      if (newToken) {
        // An impersonation token has no refresh token of its own — the cookie
        // that just refreshed is the *admin's*. So a successful refresh here
        // means the impersonation expired and we are the admin again. Reload
        // into /admin instead of retrying, which would otherwise pair an admin
        // token with the impersonated user still sitting in the store.
        if (wasImpersonating) {
          useAuthStore.getState().stopImpersonation();
          useAuthStore.getState().setAccessToken(newToken);
          if (typeof window !== "undefined") window.location.assign("/admin");
          return Promise.reject(error);
        }

        useAuthStore.getState().setAccessToken(newToken);
        original.headers = { ...original.headers, Authorization: `Bearer ${newToken}` };
        return apiClient(original);
      }

      useAuthStore.getState().clearAuth();
    }

    return Promise.reject(error);
  }
);
