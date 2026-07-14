"use client";

import { useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";

import { logout as apiLogout } from "@/lib/api/auth";
import { useAuthStore } from "@/stores/auth-store";
import { useOptionalSession } from "@/components/auth/use-optional-session";

/** Where a signed-in visitor's dashboard lives (same rule as the login page). */
function dashboardHref(role: string | undefined): string {
  return role === "super_admin" ? "/admin" : "/organizer";
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
export function PublicAuthActions({ variant = "inline" }: { variant?: "inline" | "menu" }) {
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
    router.refresh();
  };

  const guestLinks = (
    <>
      <Link
        href="/login"
        className={variant === "menu" ? undefined : "btn btn-secondary btn-sm hidden sm:inline-flex"}
      >
        Masuk
      </Link>
      <Link href="/register" className={variant === "menu" ? undefined : "btn btn-primary btn-sm"}>
        Mulai Gratis
      </Link>
    </>
  );

  // Until the refresh cookie has been exchanged we don't know who this is. Show
  // the guest links but keep them invisible: rendering nothing would make the
  // header jump once they appear, and rendering them visibly would flash "Masuk"
  // at someone who is signed in.
  if (!ready) {
    return (
      <div className="contents" style={{ visibility: "hidden" }} aria-hidden>
        {guestLinks}
      </div>
    );
  }

  if (!isAuthenticated) return guestLinks;

  if (variant === "menu") {
    return (
      <>
        <Link href={dashboardHref(user?.role)}>Dashboard</Link>
        <button type="button" onClick={handleLogout} disabled={pending} className="text-left">
          {pending ? "Keluar…" : "Keluar"}
        </button>
      </>
    );
  }

  return (
    <>
      <span className="hidden text-sm font-medium text-muted-foreground lg:inline">
        {user?.full_name}
      </span>
      <Link href={dashboardHref(user?.role)} className="btn btn-primary btn-sm">
        Dashboard
      </Link>
      <button
        type="button"
        onClick={handleLogout}
        disabled={pending}
        className="btn btn-secondary btn-sm hidden sm:inline-flex"
      >
        {pending ? "Keluar…" : "Keluar"}
      </button>
    </>
  );
}

/**
 * The landing CTAs ("Mulai Gratis"). Inviting someone who already has an account
 * to sign up again is nonsense, so they point at the dashboard instead.
 */
export function usePublicCta(): { href: string; label: string } {
  const { isAuthenticated } = useOptionalSession();
  const role = useAuthStore((s) => s.user?.role);

  return isAuthenticated
    ? { href: dashboardHref(role), label: "Ke Dashboard" }
    : { href: "/register", label: "Mulai Gratis" };
}

/**
 * The plan cards. A signed-in organizer picking a plan wants the checkout page,
 * not the sign-up form.
 */
export function usePlanCtaHref(fallback: string): string {
  const { isAuthenticated } = useOptionalSession();

  return isAuthenticated && fallback === "/register" ? "/organizer/upgrade" : fallback;
}
