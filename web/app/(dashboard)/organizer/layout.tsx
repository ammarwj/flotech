"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";
import { Loader2 } from "lucide-react";

import { ManualModeBanner } from "@/components/payment/manual-mode-banner";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { useAuthStore } from "@/stores/auth-store";

/**
 * Organizer routes need an organization. Onboarding is only pushed at register
 * time, so a user who abandoned it (logged out before naming their org) would
 * otherwise land back on a dashboard that renders hardcoded zeros and gives no
 * hint anything is missing. Sending them back to /onboarding here covers every
 * entry point — bookmark, back button, or a session restored from the refresh
 * cookie without ever touching the login page.
 *
 * Super admins are exempt: they legitimately own no organization, and forcing
 * them into onboarding would trap them in a loop.
 */
export default function OrganizerLayout({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const role = useAuthStore((s) => s.user?.role);
  const { hasNoOrg, isLoading } = useActiveOrg();

  const isAdmin = role === "super_admin";
  const mustOnboard = !isAdmin && hasNoOrg;

  useEffect(() => {
    if (mustOnboard) router.replace("/onboarding");
  }, [mustOnboard, router]);

  // Render nothing until the org is resolved, so the empty dashboard never
  // flashes before the redirect.
  if (!isAdmin && (isLoading || mustOnboard)) {
    return (
      <div className="grid min-h-[60vh] place-items-center">
        <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" aria-label="Memuat" />
      </div>
    );
  }

  return (
    <>
      <ManualModeBanner />
      {children}
    </>
  );
}
