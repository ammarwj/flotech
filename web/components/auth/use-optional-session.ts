"use client";

import { useEffect, useState } from "react";

import { me as fetchMe } from "@/lib/api/auth";
import { refreshAccessToken } from "@/lib/api/client";
import { useAuthStore } from "@/stores/auth-store";

/**
 * Restores an existing session on a *public* page, without demanding one.
 *
 * The access token lives in memory, so it is gone after every navigation into a
 * public route; only the HttpOnly refresh cookie survives. AuthGate exchanges it
 * on the dashboard, but public pages have no gate — which meant a signed-in
 * visitor filled in the team registration form and the request went out with no
 * Authorization header at all. The team was then stored with no manager, and it
 * never appeared under "Tim Saya" (PRD §5.2 promises exactly that it does).
 *
 * Unlike AuthGate this never redirects: a guest is a legitimate visitor here.
 * Callers should wait for `ready` before firing a request whose behaviour
 * depends on being signed in.
 */
export function useOptionalSession(): { ready: boolean; isAuthenticated: boolean } {
  const accessToken = useAuthStore((s) => s.accessToken);
  const setAuth = useAuthStore((s) => s.setAuth);
  const setAccessToken = useAuthStore((s) => s.setAccessToken);
  const [settled, setSettled] = useState(false);

  // Derived, not stored: a token in the store already means the session is up,
  // so there's nothing to wait for and nothing to set.
  const ready = settled || !!accessToken;

  useEffect(() => {
    // Read the token off the store rather than depending on it: `setAccessToken`
    // changes that value, and a dependency on it would tear this effect down
    // while the profile is still in flight (the same trap AuthGate fell into).
    if (useAuthStore.getState().accessToken) return;

    let active = true;

    (async () => {
      const token = await refreshAccessToken();
      if (!active) return;

      if (token) {
        setAccessToken(token);
        try {
          const user = await fetchMe();
          if (active) setAuth(token, user);
        } catch {
          // Token works even if the profile fetch doesn't; don't block the page.
        }
      }

      // Settled either way — no session is a valid outcome out here.
      if (active) setSettled(true);
    })();

    return () => {
      active = false;
    };
    // setAuth/setAccessToken are stable zustand setters, so this runs once.
  }, [setAuth, setAccessToken]);

  return { ready, isAuthenticated: !!accessToken };
}
