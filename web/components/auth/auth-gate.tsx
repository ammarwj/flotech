"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";
import { Loader2 } from "lucide-react";

import { useAuthStore } from "@/stores/auth-store";
import { refreshAccessToken } from "@/lib/api/client";
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

  // Readiness is derived: once a token exists in memory, the shell can render.
  const ready = !!accessToken;

  useEffect(() => {
    if (accessToken) return;

    let active = true;

    (async () => {
      const token = await refreshAccessToken();
      if (!active) return;

      if (!token) {
        router.replace("/login");
        return;
      }

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
  }, [accessToken, router, setAuth, setAccessToken]);

  if (!ready) {
    return (
      <div className="grid min-h-screen place-items-center">
        <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" aria-label="Memuat" />
      </div>
    );
  }

  return <>{children}</>;
}
