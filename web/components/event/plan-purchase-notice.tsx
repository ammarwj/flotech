"use client";

import Link from "next/link";
import { useState } from "react";
import { useRouter } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { PackagePlus } from "lucide-react";

import { getPublicPlans } from "@/lib/api/plans";
import { checkoutPlan } from "@/lib/api/organizations";
import { parseApiError } from "@/lib/api/errors";
import { checkoutOutcome } from "@/lib/checkout";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { PlanCard } from "@/components/subscription/plan-card";
import { PlanGrid } from "@/components/subscription/plan-grid";
import type { Plan } from "@/types/api";

/**
 * Shown on the create-event page when the organizer holds no unspent plan.
 *
 * The plan picker lives here, inline, rather than behind a link: someone who
 * came to create an event should not have to go elsewhere, buy, and find their
 * way back. This replaces the old "batas event aktif tercapai" card — that limit
 * is gone, because every event is bought separately now.
 */
export function PlanPurchaseNotice({ orgId }: { orgId?: string }) {
  const router = useRouter();
  const qc = useQueryClient();
  const [pendingPlanId, setPendingPlanId] = useState<string | null>(null);

  const plansQuery = useQuery({ queryKey: ["public-plans"], queryFn: getPublicPlans });

  const checkout = useMutation({
    mutationFn: (plan: Plan) => checkoutPlan(orgId!, plan.id),
    onSuccess: (res) => {
      const outcome = checkoutOutcome(res);

      if (outcome === "redirect") {
        window.location.assign(res.redirect_url!);
        return;
      }

      qc.invalidateQueries({ queryKey: ["organizations"] });
      qc.invalidateQueries({ queryKey: ["plan-orders"] });

      if (outcome === "manual") {
        toast.info("Selesaikan transfer manual", {
          description:
            "Payment gateway sedang mati. Transfer ke rekening flo-event dan unggah buktinya.",
        });
        router.push("/organizer/billing");
        return;
      }

      if (outcome === "failed") {
        toast.error("Pembayaran belum bisa dibuka", {
          description: "Tagihan sudah terbit tapi belum lunas. Cek halaman Pembelian Paket.",
        });
        router.push("/organizer/billing");
        return;
      }

      // Paid on the spot — the form appears as soon as the credit lands.
      toast.success("Paket siap dipakai", { description: "Lanjutkan mengisi detail eventmu." });
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal memproses pembelian.").message),
    onSettled: () => setPendingPlanId(null),
  });

  return (
    <div>
      <Card className="mb-6 max-w-2xl border-[color-mix(in_srgb,var(--brand-600)_40%,transparent)] bg-[color-mix(in_srgb,var(--brand-600)_6%,transparent)] p-6">
        <div className="flex items-start gap-3">
          <span className="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-[color-mix(in_srgb,var(--brand-600)_14%,transparent)] text-[var(--brand-600)]">
            <PackagePlus className="h-5 w-5" />
          </span>
          <div>
            <h3 className="text-base font-bold" style={{ fontFamily: "var(--font-display)" }}>
              Pilih paket untuk event ini
            </h3>
            <p className="mt-1 text-sm text-muted-foreground">
              Setiap event dibeli sekali, tanpa langganan bulanan. Batasan paket yang kamu pilih
              berlaku untuk event ini saja — event berikutnya bisa pakai paket berbeda.
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
            isPending={pendingPlanId === plan.id}
            disabled={checkout.isPending || !orgId}
            onSelect={(p) => {
              setPendingPlanId(p.id);
              checkout.mutate(p);
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
    </div>
  );
}
