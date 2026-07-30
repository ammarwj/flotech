"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";
import { isAxiosError, type AxiosRequestConfig } from "axios";
import { toast } from "sonner";
import { Loader2 } from "lucide-react";

import {
  readPendingImpersonation,
  useAuthStore,
  writePendingImpersonation,
} from "@/stores/auth-store";
import { refreshAccessToken } from "@/lib/api/client";
import { impersonateAdminUser } from "@/lib/api/admin";
import { me as fetchMe } from "@/lib/api/auth";

/**
 * Guards the authenticated app shell. Because the access token lives in memory
 * only (cleared on reload), on boot we silently exchange the HttpOnly refresh
 * cookie for a fresh token *before* rendering — so protected queries never fire
 * tokenless (no spurious 401s). If the session can't be refreshed, we redirect
 * to /login.
 */
export function AuthGate({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const accessToken = useAuthStore((s) => s.accessToken);
  const setAuth = useAuthStore((s) => s.setAuth);
  const setAccessToken = useAuthStore((s) => s.setAccessToken);
  const startImpersonation = useAuthStore((s) => s.startImpersonation);

  // Readiness is derived: once a token exists in memory, the shell can render.
  const ready = !!accessToken;

  useEffect(() => {
    // Read the token off the store instead of depending on it. `setAccessToken`
    // below changes that very value, so with it in the dependency list React
    // tears this effect down mid-flight — cleanup sets `active = false` and the
    // profile that is still being fetched is thrown away. The user then stays
    // null forever, which left a reloaded /admin page spinning behind
    // AdminLayout's `user.role === "super_admin"` check.
    if (useAuthStore.getState().accessToken) return;

    let active = true;

    (async () => {
      const token = await refreshAccessToken();
      if (!active) return;

      if (!token) {
        router.replace("/login");
        return;
      }

      // The cookie we just exchanged is always the *admin's* — impersonation
      // tokens have no refresh token of their own. So if this tab was acting as
      // someone else, mint a fresh impersonation token from the stored target
      // id instead of booting back into the admin session. This runs before
      // `ready` flips, so no admin UI flashes on an organizer page.
      const targetId = readPendingImpersonation();
      if (targetId) {
        try {
          const res = await impersonateAdminUser(targetId, {
            // The store is still empty, so the interceptor won't override this.
            headers: { Authorization: `Bearer ${token}` },
            // Keeps a 401 on this very request from re-entering the response
            // interceptor, which would find the same pending id and mint again.
            _retry: true,
          } as AxiosRequestConfig & { _retry?: boolean });
          if (active) startImpersonation(res.access_token, res.user);
          return;
        } catch (err) {
          // Only a refusal is permanent — user deleted, promoted to super
          // admin, or we are no longer an admin. A network blip or a 5xx says
          // nothing about whether this session is still allowed, and dropping
          // the id there would end a legitimate impersonation for good.
          const status = isAxiosError(err) ? err.response?.status : undefined;
          const refused = status !== undefined && status >= 400 && status < 500;

          if (refused) writePendingImpersonation(null);
          if (active) {
            toast.error(
              refused
                ? "Sesi login-sebagai berakhir."
                : "Gagal memulihkan sesi login-sebagai. Muat ulang untuk mencoba lagi."
            );
          }
        }
      }

      if (!active) return;

      // Setting the token flips `ready` and unblocks the shell; me() then
      // backfills the user profile.
      setAccessToken(token);
      try {
        const user = await fetchMe();
        if (active) setAuth(token, user);
      } catch {
        // Token is valid but profile fetch failed; let the app render anyway.
      }
    })();

    return () => {
      active = false;
    };
    // The zustand setters are stable, so this runs once.
  }, [router, setAuth, setAccessToken, startImpersonation]);

  if (!ready) {
    return (
      <div className="grid min-h-screen place-items-center">
        <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" aria-label="Memuat" />
      </div>
    );
  }

  return <>{children}</>;
}
