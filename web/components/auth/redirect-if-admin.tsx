"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";

import { useAuthStore } from "@/stores/auth-store";

/**
 * Sends super admins from the organizer dashboard to their admin home. Renders
 * nothing; drop it at the top of organizer-only pages that admins shouldn't see.
 */
export function RedirectIfAdmin() {
  const router = useRouter();
  const role = useAuthStore((s) => s.user?.role);

  useEffect(() => {
    if (role === "super_admin") router.replace("/admin");
  }, [role, router]);

  return null;
}
