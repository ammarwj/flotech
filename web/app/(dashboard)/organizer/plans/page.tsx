"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";

import { getPublicPlans } from "@/lib/api/plans";
import { checkoutPlan } from "@/lib/api/organizations";
import { parseApiError } from "@/lib/api/errors";
import { checkoutOutcome } from "@/lib/checkout";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { PlanCard } from "@/components/subscription/plan-card";
import { PlanGrid } from "@/components/subscription/plan-grid";
import { ChannelPicker } from "@/components/payment/channel-picker";
import { Dialog, DialogBody, DialogContent, DialogFooter, DialogHeader } from "@/components/ui/dialog";
import type { Plan } from "@/types/api";

/**
 * Buy a plan. One purchase covers one event, so there is nothing to "upgrade"
 * from and no current plan to mark — an organizer may hold several credits and
 * run events on different tiers at the same time.
 */
export default function PlansPage() {
  const router = useRouter();
  const qc = useQueryClient();
  const { org, orgId, isLoading: orgLoading } = useActiveOrg();
  const [pendingPlanId, setPendingPlanId] = useState<string | null>(null);
  // Set only while the gateway is live and a plan is waiting on a channel
  // choice — manual checkout never shows this dialog.
  const [channelPlan, setChannelPlan] = useState<Plan | null>(null);
  const [channel, setChannel] = useState<string | null>(null);

  const plansQuery = useQuery({ queryKey: ["public-plans"], queryFn: getPublicPlans });

  const checkout = useMutation({
    mutationFn: ({ plan, channel }: { plan: Plan; channel: string | null }) =>
      checkoutPlan(orgId!, plan.id, channel ?? undefined),
    onSuccess: (res) => {
      const outcome = checkoutOutcome(res);

      if (outcome === "redirect") {
        window.location.assign(res.redirect_url!);
        return;
      }

      qc.invalidateQueries({ queryKey: ["organizations"] });
      qc.invalidateQueries({ queryKey: ["plan-orders"] });

      if (outcome === "manual") {
        // The transfer panel lives on the billing page, not in this grid.
        toast.info("Selesaikan transfer manual", {
          description:
            "Payment gateway sedang mati. Transfer ke rekening flo-event dan unggah buktinya.",
        });
        router.push("/organizer/billing");
        return;
      }

      if (outcome === "failed") {
        toast.error("Pembayaran belum bisa dibuka", {
          description:
            "Tagihan sudah terbit tapi belum lunas. Coba lagi dari halaman Pembelian Paket.",
        });
        router.push("/organizer/billing");
        return;
      }

      toast.success("Paket siap dipakai", {
        description: "Paketnya menunggu satu event. Tidak ada masa berlaku.",
      });
      router.push("/organizer/events/new");
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal memproses pembelian.").message),
    onSettled: () => {
      setPendingPlanId(null);
      setChannelPlan(null);
      setChannel(null);
    },
  });

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
            isPending={pendingPlanId === plan.id}
            disabled={checkout.isPending}
            onSelect={(p) => {
              if (org?.payment_gateway_enabled) {
                setChannel(null);
                setChannelPlan(p);
                return;
              }
              setPendingPlanId(p.id);
              checkout.mutate({ plan: p, channel: null });
            }}
          />
        ))}
      </PlanGrid>

      <Dialog
        open={!!channelPlan}
        onOpenChange={(next) => !checkout.isPending && !next && setChannelPlan(null)}
      >
        <DialogContent>
          <DialogHeader
            title="Pilih metode pembayaran"
            description={channelPlan ? `Beli paket ${channelPlan.name}.` : undefined}
          />
          <DialogBody>
            {channelPlan && (
              <ChannelPicker
                amount={channelPlan.price}
                audience="organizer"
                value={channel}
                onChange={setChannel}
              />
            )}
          </DialogBody>
          <DialogFooter>
            <Button variant="secondary" onClick={() => setChannelPlan(null)} disabled={checkout.isPending}>
              Batal
            </Button>
            <Button
              disabled={!channel || checkout.isPending}
              onClick={() => {
                if (!channelPlan) return;
                setPendingPlanId(channelPlan.id);
                checkout.mutate({ plan: channelPlan, channel });
              }}
            >
              Lanjutkan
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
