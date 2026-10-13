"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";

import { getPublicPlans } from "@/lib/api/plans";
import { checkoutPlan, previewDiscount } from "@/lib/api/organizations";
import { parseApiError } from "@/lib/api/errors";
import { checkoutOutcome } from "@/lib/checkout";
import { rupiah } from "@/lib/labels";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { PlanCard } from "@/components/subscription/plan-card";
import { PlanGrid } from "@/components/subscription/plan-grid";
import { ChannelPicker } from "@/components/payment/channel-picker";
import { Dialog, DialogBody, DialogContent, DialogFooter, DialogHeader } from "@/components/ui/dialog";
import type { DiscountPreview, Plan } from "@/types/api";

/**
 * Buy a plan. One purchase covers one event, so there is nothing to "upgrade"
 * from and no current plan to mark — an organizer may hold several credits and
 * run events on different tiers at the same time.
 *
 * The dialog opens on *both* rails now, not only while the gateway is live: the
 * discount field has to be reachable when payment is a manual transfer too. The
 * channel picker inside it is still gateway-only.
 */
export default function PlansPage() {
  const router = useRouter();
  const qc = useQueryClient();
  const { org, orgId, isLoading: orgLoading } = useActiveOrg();
  const [pendingPlanId, setPendingPlanId] = useState<string | null>(null);
  // The plan waiting on the checkout dialog — channel choice, discount code, or
  // simply a confirmation on the manual rail.
  const [dialogPlan, setDialogPlan] = useState<Plan | null>(null);
  const [channel, setChannel] = useState<string | null>(null);
  const [codeInput, setCodeInput] = useState("");
  // Server-resolved, never computed here: the fee preview below is built from
  // `applied.amount`, so a discount worked out in the browser would make the
  // channel picker quote a fee on a price nobody is being charged.
  const [applied, setApplied] = useState<DiscountPreview | null>(null);
  const [codeError, setCodeError] = useState<string | null>(null);

  const plansQuery = useQuery({ queryKey: ["public-plans"], queryFn: getPublicPlans });

  const gatewayLive = !!org?.payment_gateway_enabled;
  const payable = applied ? applied.amount : (dialogPlan?.price ?? 0);
  // Free after a full discount: there is no channel to pick, and the server
  // settles the order outright.
  const needsChannel = gatewayLive && payable > 0;

  const resetDialog = () => {
    setDialogPlan(null);
    setChannel(null);
    setCodeInput("");
    setApplied(null);
    setCodeError(null);
  };

  const discount = useMutation({
    mutationFn: ({ plan, code }: { plan: Plan; code: string }) =>
      previewDiscount(orgId!, plan.id, code),
    onSuccess: (preview) => {
      setApplied(preview);
      setCodeError(null);
      // A discount changes the amount the fee is a percentage of, so the
      // previously picked channel's quote is stale.
      setChannel(null);
    },
    onError: (err) => {
      const parsed = parseApiError(err, "Kode diskon tidak bisa dipakai.");
      setApplied(null);
      setCodeError(parsed.fieldErrors.discount_code ?? parsed.message);
    },
  });

  const checkout = useMutation({
    mutationFn: ({ plan, channel, code }: { plan: Plan; channel: string | null; code?: string }) =>
      checkoutPlan(orgId!, plan.id, channel ?? undefined, code),
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
    onError: (err) => {
      const parsed = parseApiError(err, "Gagal memproses pembelian.");

      // A code that passed the preview can still be refused here if its quota
      // ran out in between — keep it on the field rather than in a toast.
      if (parsed.fieldErrors.discount_code) {
        setCodeError(parsed.fieldErrors.discount_code);
        setApplied(null);
        return;
      }

      toast.error(parsed.message);
    },
    onSettled: (_data, error) => {
      setPendingPlanId(null);
      if (!error) resetDialog();
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
              resetDialog();
              setDialogPlan(p);
            }}
          />
        ))}
      </PlanGrid>

      <Dialog
        open={!!dialogPlan}
        onOpenChange={(next) => !checkout.isPending && !next && resetDialog()}
      >
        <DialogContent>
          <DialogHeader
            title={gatewayLive ? "Pilih metode pembayaran" : "Konfirmasi pembelian"}
            description={dialogPlan ? `Beli paket ${dialogPlan.name}.` : undefined}
          />
          <DialogBody>
            {dialogPlan && (
              <div className="grid gap-4">
                <div className="grid gap-2">
                  <Label htmlFor="discount-code">Kode diskon (opsional)</Label>
                  <div className="flex gap-2">
                    <Input
                      id="discount-code"
                      value={codeInput}
                      placeholder="LAUNCH50"
                      autoCapitalize="characters"
                      disabled={checkout.isPending}
                      onChange={(e) => {
                        setCodeInput(e.target.value);
                        setCodeError(null);
                        setApplied(null);
                      }}
                    />
                    <Button
                      variant="secondary"
                      disabled={!codeInput.trim() || discount.isPending || checkout.isPending}
                      onClick={() => discount.mutate({ plan: dialogPlan, code: codeInput.trim() })}
                    >
                      {discount.isPending ? "Memeriksa…" : "Pakai"}
                    </Button>
                  </div>
                  {codeError && <p className="text-sm text-[var(--danger)]">{codeError}</p>}
                </div>

                <div className="grid gap-1 rounded-lg border p-3 text-sm">
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">Harga paket</span>
                    <span>{rupiah(dialogPlan.price)}</span>
                  </div>
                  {applied && (
                    <div className="flex justify-between text-[var(--success)]">
                      <span>Diskon {applied.code}</span>
                      <span>&minus;{rupiah(applied.discount_amount)}</span>
                    </div>
                  )}
                  <div className="flex justify-between font-medium">
                    <span>Yang dibayar</span>
                    <span>{rupiah(payable)}</span>
                  </div>
                  {payable === 0 && (
                    <p className="text-xs text-muted-foreground">
                      Tidak ada yang perlu dibayar — paketnya langsung siap dipakai.
                    </p>
                  )}
                </div>

                {needsChannel && (
                  <ChannelPicker
                    amount={payable}
                    audience="organizer"
                    value={channel}
                    onChange={setChannel}
                  />
                )}
              </div>
            )}
          </DialogBody>
          <DialogFooter>
            <Button variant="secondary" onClick={resetDialog} disabled={checkout.isPending}>
              Batal
            </Button>
            <Button
              disabled={(needsChannel && !channel) || checkout.isPending}
              onClick={() => {
                if (!dialogPlan) return;
                setPendingPlanId(dialogPlan.id);
                checkout.mutate({
                  plan: dialogPlan,
                  channel: needsChannel ? channel : null,
                  // Only a code the preview accepted is ever sent.
                  code: applied?.code,
                });
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
