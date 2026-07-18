"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";

import { getPublicPlans } from "@/lib/api/plans";
import { checkoutSubscription } from "@/lib/api/organizations";
import { parseApiError } from "@/lib/api/errors";
import { getActiveEventLimit, getMaxYearlyDiscount } from "@/lib/plan";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import {
  BillingCycleToggle,
  PlanCard,
  type BillingCycle,
} from "@/components/subscription/plan-card";
import { PlanGrid } from "@/components/subscription/plan-grid";
import type { Plan } from "@/types/api";

export default function UpgradePage() {
  const router = useRouter();
  const qc = useQueryClient();
  const { org, orgId, isLoading: orgLoading } = useActiveOrg();
  const [cycle, setCycle] = useState<BillingCycle>("monthly");
  const [pendingPlanId, setPendingPlanId] = useState<string | null>(null);

  const plansQuery = useQuery({ queryKey: ["public-plans"], queryFn: getPublicPlans });

  const checkout = useMutation({
    mutationFn: (plan: Plan) => checkoutSubscription(orgId!, plan.id, cycle),
    onSuccess: (res) => {
      if (res.redirect_url) {
        window.location.assign(res.redirect_url);
        return;
      }
      // Dev/mock: subscription auto-activated and the plan switched.
      qc.invalidateQueries({ queryKey: ["organizations"] });
      qc.invalidateQueries({ queryKey: ["subscriptions"] });
      toast.success("Paket berhasil diperbarui", {
        description: "Kapasitas event aktifmu kini bertambah.",
        action: { label: "Buat event", onClick: () => router.push("/organizer/events/new") },
      });
      router.push("/organizer/subscription");
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal memproses upgrade.").message),
    onSettled: () => setPendingPlanId(null),
  });

  if (orgLoading) {
    return (
      <div>
        <PageHeader title="Upgrade paket" backHref="/organizer/subscription" backLabel="Langganan" />
        <PlanGrid count={4}>
          {[0, 1, 2, 3].map((i) => (
            <Skeleton key={i} className="h-56 w-full rounded-xl" />
          ))}
        </PlanGrid>
      </div>
    );
  }

  const currentPlanId = org?.plan_id ?? org?.plan?.id ?? null;
  const limit = getActiveEventLimit(org);

  return (
    <div>
      <PageHeader
        title="Upgrade paket"
        description={
          org?.plan
            ? `Paket saat ini: ${org.plan.name}${
                limit !== null ? ` — maks ${limit} event aktif` : " — event aktif tanpa batas"
              }.`
            : "Pilih paket yang sesuai dengan skala turnamenmu."
        }
        backHref="/organizer/subscription"
        backLabel="Langganan"
      />

      <BillingCycleToggle
        cycle={cycle}
        onChange={setCycle}
        discount={getMaxYearlyDiscount(plansQuery.data)}
      />

      {plansQuery.isLoading && (
        <PlanGrid count={4}>
          {[0, 1, 2, 3].map((i) => (
            <Skeleton key={i} className="h-56 w-full rounded-xl" />
          ))}
        </PlanGrid>
      )}
      {plansQuery.isError && (
        <p className="text-sm text-[var(--danger)]">Gagal memuat paket. Pastikan API berjalan.</p>
      )}

      <PlanGrid count={plansQuery.data?.length ?? 0}>
        {plansQuery.data?.map((plan) => (
          <PlanCard
            key={plan.id}
            plan={plan}
            cycle={cycle}
            isCurrent={plan.id === currentPlanId}
            isPending={pendingPlanId === plan.id}
            disabled={checkout.isPending}
            onSelect={(p) => {
              setPendingPlanId(p.id);
              checkout.mutate(p);
            }}
          />
        ))}
      </PlanGrid>
    </div>
  );
}
