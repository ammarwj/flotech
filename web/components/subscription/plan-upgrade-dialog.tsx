"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { ArrowUpCircle, Check } from "lucide-react";

import { getPlanUpgradeOptions, upgradePlanOrder } from "@/lib/api/organizations";
import { parseApiError } from "@/lib/api/errors";
import { checkoutOutcome } from "@/lib/checkout";
import { formatPlanFeature, getPlanColor } from "@/lib/plan";
import { rupiah } from "@/lib/labels";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Dialog,
  DialogBody,
  DialogContent,
  DialogFooter,
  DialogHeader,
} from "@/components/ui/dialog";
import type { EventPlanOrder, PlanUpgradeOption } from "@/types/api";

/**
 * Move a paid plan up a tier, paying the difference.
 *
 * There is no downgrade here and there is no downgrade behind it: the server
 * only offers plans that grant everything the current one does, which is the
 * same test the checkout enforces (PlanGate::planCovers). So this list is never
 * a promise the till will break — and it is why the dialog can present every
 * option as simply "available" without second-guessing.
 *
 * Used from two places with the same props: a credit on /organizer/billing, and
 * the plan an event is already running on.
 */
export function PlanUpgradeDialog({
  orgId,
  order,
  open,
  onOpenChange,
}: {
  orgId: string;
  order: EventPlanOrder;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const router = useRouter();
  const qc = useQueryClient();
  const [pendingPlanId, setPendingPlanId] = useState<string | null>(null);

  const optionsQuery = useQuery({
    queryKey: ["plan-upgrade-options", orgId, order.id],
    queryFn: () => getPlanUpgradeOptions(orgId, order.id),
    // Only while the dialog is open: the list is a few rows and the organizer
    // may never open it, so there is nothing to gain by fetching it for every
    // order in a history table.
    enabled: open,
  });

  const upgrade = useMutation({
    mutationFn: (planId: string) => upgradePlanOrder(orgId, order.id, planId),
    onSuccess: (res) => {
      const outcome = checkoutOutcome(res);

      if (outcome === "redirect") {
        window.location.assign(res.redirect_url!);
        return;
      }

      qc.invalidateQueries({ queryKey: ["plan-orders"] });
      qc.invalidateQueries({ queryKey: ["organizations"] });
      qc.invalidateQueries({ queryKey: ["events"] });
      onOpenChange(false);

      if (outcome === "manual") {
        toast.info("Selesaikan transfer manual", {
          description:
            "Payment gateway sedang mati. Transfer selisihnya ke rekening flo-event dan unggah buktinya.",
        });
        router.push("/organizer/billing");
        return;
      }

      if (outcome === "failed") {
        toast.error("Pembayaran belum bisa dibuka", {
          description: "Tagihan upgrade sudah terbit tapi belum lunas. Cek halaman Pembelian Paket.",
        });
        router.push("/organizer/billing");
        return;
      }

      toast.success("Paket berhasil dinaikkan", {
        description: "Batasan barunya langsung berlaku untuk event ini.",
      });
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal memproses upgrade.").message),
    onSettled: () => setPendingPlanId(null),
  });

  const options = optionsQuery.data ?? [];

  return (
    <Dialog open={open} onOpenChange={(next) => !upgrade.isPending && onOpenChange(next)}>
      <DialogContent className="max-w-2xl">
        <DialogHeader
          icon={ArrowUpCircle}
          title="Naikkan paket"
          description={
            order.plan
              ? `Sekarang di ${order.plan.name}. Kamu hanya membayar selisihnya.`
              : "Kamu hanya membayar selisihnya."
          }
        />

        <DialogBody>
          {optionsQuery.isLoading && <Skeleton className="h-40 w-full rounded-lg" />}

          {optionsQuery.isError && (
            <p className="text-sm text-[var(--danger)]">
              Gagal memuat pilihan paket. Coba tutup dan buka lagi.
            </p>
          )}

          {/* Not an error state: an organizer already on the top plan has
              nothing above them, and saying so plainly beats an empty box. */}
          {!optionsQuery.isLoading && !optionsQuery.isError && options.length === 0 && (
            <p className="text-sm text-muted-foreground">
              Tidak ada paket yang lebih tinggi dari yang kamu pakai sekarang.
            </p>
          )}

          <div className="grid gap-3">
            {options.map((option: PlanUpgradeOption) => (
              <div
                key={option.plan.id}
                className="rounded-lg border border-border p-4"
                style={{ borderLeft: `3px solid ${getPlanColor(option.plan.slug)}` }}
              >
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                  <b className="text-[15px]" style={{ fontFamily: "var(--font-display)" }}>
                    {option.plan.name}
                  </b>
                  <span className="text-sm">
                    <span className="text-muted-foreground">Tambah bayar </span>
                    <b>{rupiah(option.price_difference)}</b>
                  </span>
                </div>

                {/* Only what this move adds. Listing the whole matrix again
                    would bury the answer to "what do I actually get?" — and
                    every row here is guaranteed to be a gain, because a plan
                    that took something away is not offered at all. */}
                <ul className="mt-3 grid gap-1.5">
                  {(option.plan.feature_details ?? [])
                    .filter((f) => f.included)
                    .map((f) => (
                      <li key={f.key} className="flex items-start gap-2 text-[13px] text-[var(--text-2)]">
                        <Check className="mt-0.5 h-4 w-4 shrink-0 text-[var(--success)]" />
                        {formatPlanFeature(f)}
                      </li>
                    ))}
                </ul>

                <Button
                  className="mt-4"
                  size="sm"
                  disabled={upgrade.isPending}
                  onClick={() => {
                    setPendingPlanId(option.plan.id);
                    upgrade.mutate(option.plan.id);
                  }}
                >
                  {pendingPlanId === option.plan.id ? "Memproses…" : `Naik ke ${option.plan.name}`}
                </Button>
              </div>
            ))}
          </div>
        </DialogBody>

        <DialogFooter>
          <Button variant="secondary" onClick={() => onOpenChange(false)} disabled={upgrade.isPending}>
            Tutup
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
