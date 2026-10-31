"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useQuery } from "@tanstack/react-query";
import { toast } from "sonner";

import { getPublicPlans } from "@/lib/api/plans";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { Skeleton } from "@/components/ui/skeleton";
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
        orgId={orgId ?? undefined}
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
