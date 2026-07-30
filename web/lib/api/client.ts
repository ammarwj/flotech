import axios, {
  AxiosError,
  isAxiosError,
  type AxiosRequestConfig,
  type InternalAxiosRequestConfig,
} from "axios";
import { toast } from "sonner";

import { readPendingImpersonation, useAuthStore } from "@/stores/auth-store";

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

      const newToken = await refreshAccessToken();

      if (newToken) {
        // An impersonation token has no refresh token of its own, so the cookie
        // that just refreshed is always the *admin's*. That makes this token the
        // credential for minting a new impersonation token — not the session we
        // want — for as long as a target id is still pending. Ejecting here
        // instead (as this once did) called stopImpersonation(), which erases
        // that id: the one thing AuthGate needs to restore the session on boot.
        // A single 401 then ended the impersonation permanently, and with
        // JWT_TTL at 15 minutes that was a matter of waiting.
        const targetId = readPendingImpersonation();
        if (targetId) {
          try {
            const { impersonateAdminUser } = await import("./admin");
            const res = await impersonateAdminUser(targetId, {
              // The store still holds the dead token, so the request
              // interceptor would override this one — say so explicitly.
              headers: { Authorization: `Bearer ${newToken}` },
              // Without this the mint itself re-enters this branch on a 401,
              // finds the same id, and mints forever.
              _retry: true,
            } as RetryConfig);
            useAuthStore.getState().startImpersonation(res.access_token, res.user);
            original.headers = {
              ...original.headers,
              Authorization: `Bearer ${res.access_token}`,
            };
            return apiClient(original);
          } catch (mintError) {
            const status = isAxiosError(mintError) ? mintError.response?.status : undefined;

            // Only a refusal is permanent — the target was deleted or promoted,
            // or we are no longer an admin. Now, and only now, is falling back
            // to the admin right. A network blip or a 5xx says nothing about
            // whether this session is still allowed, so it leaves everything
            // (including the pending id) alone and the next request retries.
            if (status !== undefined && status >= 400 && status < 500) {
              useAuthStore.getState().stopImpersonation();
              useAuthStore.getState().setAccessToken(newToken);
              toast.error("Sesi login-sebagai berakhir.");
              if (typeof window !== "undefined") window.location.assign("/admin");
            }

            return Promise.reject(error);
          }
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
