"use client";

import { useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";

import { logout as apiLogout } from "@/lib/api/auth";
import { useAuthStore, type AuthUser } from "@/stores/auth-store";
import { useOptionalSession } from "@/components/auth/use-optional-session";
import { MODE_HOME } from "@/lib/hooks/use-dashboard-mode";

/**
 * Where a signed-in visitor's dashboard lives (same rule as the login page):
 * super admin → admin, otherwise the hat they wore last (`default_mode`).
 */
function dashboardHref(user: AuthUser | null): string {
  if (user?.role === "super_admin") return "/admin";
  return MODE_HOME[user?.default_mode ?? "organizer"];
}

/**
 * The sign-in state of a public header.
 *
 * Public pages have no AuthGate, and the access token only lives in memory — so
 * without `useOptionalSession` restoring it from the refresh cookie, a signed-in
 * visitor looked like a guest out here and was invited to "Masuk" again.
 *
 * Signing out stays on the page: a guest is a legitimate visitor of a public
 * page, so there is nothing to redirect away from (unlike the dashboard's
 * UserMenu, which bounces to /login).
 */
export function PublicAuthActions({
  variant = "inline",
  onNavigate,
}: {
  variant?: "inline" | "menu";
  /** Dismiss the surrounding mobile sheet once an action is taken. */
  onNavigate?: () => void;
}) {
  const router = useRouter();
  const { ready, isAuthenticated } = useOptionalSession();
  const user = useAuthStore((s) => s.user);
  const clearAuth = useAuthStore((s) => s.clearAuth);
  const [pending, setPending] = useState(false);

  const handleLogout = async () => {
    setPending(true);
    try {
      await apiLogout(); // revokes the refresh token + clears the HttpOnly cookie
    } catch {
      // Drop local auth regardless, so the user is signed out either way.
    }
    clearAuth();
    setPending(false);
    onNavigate?.();
    router.refresh();
  };

  // In the sheet these are the panel's primary actions, so they get full-width
  // buttons — the same shape as the dashboard sheet's "Keluar".
  const menu = variant === "menu";
  const cls = (kind: "primary" | "secondary") =>
    menu ? `btn btn-${kind} btn-block` : `btn btn-${kind} btn-sm`;

  const guestLinks = (
    <>
      <Link href="/login" className={cls("secondary")} onClick={onNavigate}>
        Masuk
      </Link>
      <Link href="/register" className={cls("primary")} onClick={onNavigate}>
        Daftar
      </Link>
    </>
  );

  // Until the refresh cookie has been exchanged we don't know who this is — and
  // even once the token is set, `user` (with its `default_mode`) lands a beat
  // later from /auth/me. Hold the invisible placeholder through both: rendering
  // "Masuk" would flash at someone signed in, and rendering "Dashboard" before
  // `user` exists would point it at the wrong dashboard (default /organizer).
  if (!ready || (isAuthenticated && !user)) {
    // The placeholder is there to hold the bar's width steady while the session
    // resolves. A menu that only exists once tapped has no such shift to avoid,
    // and reserving the space there just shows two blank rows that then jump.
    if (variant === "menu") return null;

    return (
      <div className="contents" style={{ visibility: "hidden" }} aria-hidden>
        {guestLinks}
      </div>
    );
  }

  if (!isAuthenticated) return guestLinks;

  if (menu) {
    return (
      <>
        <Link href={dashboardHref(user)} className={cls("primary")} onClick={onNavigate}>
          Dashboard
        </Link>
        <button
          type="button"
          onClick={handleLogout}
          disabled={pending}
          className={cls("secondary")}
        >
          {pending ? "Keluar…" : "Keluar"}
        </button>
      </>
    );
  }

  return (
    <Link href={dashboardHref(user)} className={cls("primary")}>
      Dashboard
    </Link>
  );
}

/**
 * The landing CTAs ("Mulai Sekarang"). Inviting someone who already has an
 * account to sign up again is nonsense, so they point at the dashboard instead.
 */
export function usePublicCta(): { href: string; label: string } {
  const { isAuthenticated } = useOptionalSession();
  const user = useAuthStore((s) => s.user);

  // Wait for `user` (its `default_mode`) before pointing at a dashboard — before
  // it lands, isAuthenticated is already true but the destination is unknown.
  return isAuthenticated && user
    ? { href: dashboardHref(user), label: "Ke Dashboard" }
    : { href: "/register", label: "Mulai Sekarang" };
}

/**
 * The plan cards. A signed-in organizer picking a plan wants the checkout page,
 * not the sign-up form.
 */
export function usePlanCtaHref(fallback: string): string {
  const { isAuthenticated } = useOptionalSession();

  return isAuthenticated && fallback === "/register" ? "/organizer/upgrade" : fallback;
}
