"use client";

import { useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useQuery } from "@tanstack/react-query";
import { toast } from "sonner";
import { Building2 } from "lucide-react";

import { getPublicPlans } from "@/lib/api/plans";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { EmptyState } from "@/components/shared/empty-state";
import { PageHeader } from "@/components/shared/page-header";
import { PlanCard } from "@/components/subscription/plan-card";
import { PlanGrid } from "@/components/subscription/plan-grid";
import { PlanCheckoutDialog } from "@/components/subscription/plan-checkout-dialog";
import type { Plan } from "@/types/api";

/**
 * Buy a plan. One purchase covers one event, so there is nothing to "upgrade"
 * from and no current plan to mark — an organizer may hold several credits and
 * run events on different tiers at the same time.
 *
 * The checkout dialog (code, price, channel) is `PlanCheckoutDialog`, shared
 * with the create-event page so the two cannot answer differently.
 */
export default function PlansPage() {
  const router = useRouter();
  const { org, orgId, isLoading: orgLoading } = useActiveOrg();
  const [pendingPlanId, setPendingPlanId] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  // The plan waiting on the checkout dialog — channel choice, discount code, or
  // simply a confirmation on the manual rail.
  const [dialogPlan, setDialogPlan] = useState<Plan | null>(null);

  const plansQuery = useQuery({ queryKey: ["public-plans"], queryFn: getPublicPlans });

  if (orgLoading || plansQuery.isLoading) {
    return (
      <div>
        <PageHeader title="Beli paket" backHref="/organizer/billing" backLabel="Pembelian Paket" />
        <PlanGrid count={3}>
          {[0, 1, 2].map((i) => (
            <Skeleton key={i} className="h-56 w-full rounded-xl" />
          ))}
        </PlanGrid>
      </div>
    );
  }

  // A plan is bought *by* an organization, and the id goes straight into the
  // URL — so no org means there is nothing to buy on behalf of, not a form to
  // render and fail at the till. /organizer/layout sends an ordinary user to
  // onboarding, but a super admin is exempt there (they legitimately own none),
  // and they are exactly who reaches this page with `orgId` null.
  if (!orgId) {
    return (
      <div>
        <PageHeader title="Beli paket" backHref="/organizer/billing" backLabel="Pembelian Paket" />
        <EmptyState
          icon={Building2}
          title="Belum punya organisasi"
          description="Paket dibeli atas nama organisasi. Buat organisasi dulu, lalu pilih paketnya di sini."
          action={
            <Button asChild>
              <Link href="/onboarding">Buat organisasi</Link>
            </Button>
          }
        />
      </div>
    );
  }

  return (
    <div>
      <PageHeader
        title="Beli paket"
        description="Bayar sekali untuk satu event. Event yang lintas bulan tidak dikenai biaya tambahan."
        backHref="/organizer/billing"
        backLabel="Pembelian Paket"
      />

      {plansQuery.isError && (
        <p className="text-sm text-[var(--danger)]">Gagal memuat paket. Pastikan API berjalan.</p>
      )}

      <PlanGrid count={plansQuery.data?.length ?? 0}>
        {plansQuery.data?.map((plan) => (
          <PlanCard
            key={plan.id}
            plan={plan}
            isPending={pendingPlanId === plan.id && busy}
            disabled={busy}
            onSelect={(p) => {
              setPendingPlanId(p.id);
              setDialogPlan(p);
            }}
          />
        ))}
      </PlanGrid>

      <PlanCheckoutDialog
        plan={dialogPlan}
        orgId={orgId}
        gatewayLive={!!org?.payment_gateway_enabled}
        onClose={() => {
          setDialogPlan(null);
          setPendingPlanId(null);
        }}
        onBusyChange={setBusy}
        onPaid={() => {
          toast.success("Paket siap dipakai", {
            description: "Paketnya menunggu satu event. Tidak ada masa berlaku.",
          });
          router.push("/organizer/events/new");
        }}
      />
    </div>
  );
}
