"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";
import { Loader2 } from "lucide-react";

import { ManualModeBanner } from "@/components/payment/manual-mode-banner";
import { PlanPaymentPendingBanner } from "@/components/payment/plan-payment-pending-banner";
import { UnconsumedPlanBanner } from "@/components/payment/unconsumed-plan-banner";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { hasOperatorAccess } from "@/lib/hooks/use-dashboard-mode";
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
 * them into onboarding would trap them in a loop. Event operators are exempt
 * for the same reason one step removed — see `hasOperatorAccess()`: the whole
 * point of the role is a user who holds no `organization_members` seat at
 * all, and the route they land on (one specific event's sub-pages) needs no
 * org context from this layout to render.
 */
export default function OrganizerLayout({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const user = useAuthStore((s) => s.user);
  const { hasNoOrg, isLoading } = useActiveOrg();

  const isAdmin = user?.role === "super_admin";
  const isOperatorOnly = hasOperatorAccess(user ?? {});
  const mustOnboard = !isAdmin && !isOperatorOnly && hasNoOrg;

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
      <PlanPaymentPendingBanner />
      <UnconsumedPlanBanner />
      {children}
    </>
  );
}
