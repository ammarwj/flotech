"use client";

import Link from "next/link";
import { useState } from "react";
import { toast } from "sonner";
import { PackagePlus } from "lucide-react";

import { getPublicPlans } from "@/lib/api/plans";
import { useQuery } from "@tanstack/react-query";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { PlanCard } from "@/components/subscription/plan-card";
import { PlanGrid } from "@/components/subscription/plan-grid";
import { PlanCheckoutDialog } from "@/components/subscription/plan-checkout-dialog";
import type { Plan } from "@/types/api";

/**
 * Shown on the create-event page when the organizer holds no unspent plan.
 *
 * The plan picker lives here, inline, rather than behind a link: someone who
 * came to create an event should not have to go elsewhere, buy, and find their
 * way back. This replaces the old "batas event aktif tercapai" card — that limit
 * is gone, because every event is bought separately now.
 *
 * Checkout itself is `PlanCheckoutDialog`, the same component `/organizer/plans`
 * renders. It used to be a second copy that opened only while the gateway was
 * live and carried no discount field at all, so this page — the one an organizer
 * actually reaches when buying — was where a code could not be typed.
 */
export function PlanPurchaseNotice({ orgId }: { orgId: string | null }) {
  const { org } = useActiveOrg();
  const [pendingPlanId, setPendingPlanId] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [dialogPlan, setDialogPlan] = useState<Plan | null>(null);

  const plansQuery = useQuery({ queryKey: ["public-plans"], queryFn: getPublicPlans });

  return (
    <div>
      <Card className="mb-6 border-[color-mix(in_srgb,var(--brand-600)_40%,transparent)] bg-[color-mix(in_srgb,var(--brand-600)_6%,transparent)] p-6">
        <div className="flex items-start gap-3">
          <span className="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-[color-mix(in_srgb,var(--brand-600)_14%,transparent)] text-[var(--brand-600)]">
            <PackagePlus className="h-5 w-5" />
          </span>
          <div>
            <h3 className="text-base font-bold" style={{ fontFamily: "var(--font-display)" }}>
              Pilih paket untuk event ini
            </h3>
            <p className="mt-1 text-sm text-muted-foreground">
              Bayar sekali, berlaku untuk satu event. Tanpa langganan.
            </p>
          </div>
        </div>
      </Card>

      {plansQuery.isLoading && (
        <PlanGrid count={3}>
          {[0, 1, 2].map((i) => (
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
            isPending={pendingPlanId === plan.id && busy}
            disabled={busy || !orgId}
            onSelect={(p) => {
              setPendingPlanId(p.id);
              setDialogPlan(p);
            }}
          />
        ))}
      </PlanGrid>

      <p className="mt-5 text-sm text-muted-foreground">
        Sudah pernah beli tapi belum dipakai?{" "}
        <Link href="/organizer/billing" className="underline underline-offset-2">
          Cek Pembelian Paket
        </Link>
        .
      </p>

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
          // Paid on the spot — the form appears as soon as the credit lands, so
          // there is nowhere to send them.
          toast.success("Paket siap dipakai", { description: "Lanjutkan mengisi detail eventmu." });
        }}
      />
    </div>
  );
}
