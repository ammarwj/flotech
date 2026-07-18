"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { toast } from "sonner";

import { logout as apiLogout } from "@/lib/api/auth";
import { useAuthStore } from "@/stores/auth-store";

/**
 * Signing out of the dashboard. Shared by the desktop header (`UserMenu`) and
 * the mobile hamburger panel (`MobileMenu`) so the two can never drift into
 * signing out differently — the mobile one is the only way out on a phone.
 *
 * Unlike the public header's sign-out, this one bounces to /login: every page
 * behind it is gated, so staying put would just hit the AuthGate.
 */
export function useLogout(): { logout: () => Promise<void>; pending: boolean } {
  const router = useRouter();
  const clearAuth = useAuthStore((s) => s.clearAuth);
  const [pending, setPending] = useState(false);

  const logout = async () => {
    setPending(true);
    try {
      await apiLogout(); // revokes refresh token + clears the HttpOnly cookie
    } catch {
      // Even if the call fails, drop local auth so the user is signed out.
    }
    clearAuth();
    toast.success("Berhasil keluar");
    router.replace("/login");
  };

  return { logout, pending };
}
