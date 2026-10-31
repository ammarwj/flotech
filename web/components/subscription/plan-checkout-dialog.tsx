"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";

import { checkoutPlan, previewDiscount } from "@/lib/api/organizations";
import { parseApiError } from "@/lib/api/errors";
import { checkoutOutcome } from "@/lib/checkout";
import { rupiah } from "@/lib/labels";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { ChannelPicker } from "@/components/payment/channel-picker";
import { Dialog, DialogBody, DialogContent, DialogFooter, DialogHeader } from "@/components/ui/dialog";
import type { DiscountPreview, Plan } from "@/types/api";

/**
 * The plan checkout dialog: discount code, price breakdown, channel picker.
 *
 * Two surfaces buy a plan — `/organizer/plans` and the notice on the create-event
 * page — and they held two copies of this. The copies drifted exactly the way
 * `checkoutOutcome()`'s docblock warns about: the create-event one was written
 * before discount codes and never grew the field, so the organizer who came to
 * buy a plan *while making an event* was the one person who could not type a
 * code. One component, so that cannot happen again.
 *
 * It opens on *both* rails, not only while the gateway is live: the discount
 * field has to be reachable when payment is a manual transfer too. The channel
 * picker inside it is still gateway-only.
 *
 * `redirect`/`manual`/`failed` are handled here because every caller answers
 * them identically. Only `paid` differs — one page sends the organizer to the
 * create-event form, the other already *is* that form — so that one is `onPaid`.
 */
export function PlanCheckoutDialog({
  plan,
  orgId,
  gatewayLive,
  onClose,
  onPaid,
  onBusyChange,
}: {
  /** The plan being bought; null closes the dialog. */
  plan: Plan | null;
  orgId?: string;
  gatewayLive: boolean;
  onClose: () => void;
  /** Settled on the spot — the credit is in hand. Say so and go wherever it is spent. */
  onPaid: () => void;
  onBusyChange?: (busy: boolean) => void;
}) {
  const router = useRouter();
  const qc = useQueryClient();
  const [channel, setChannel] = useState<string | null>(null);
  const [codeInput, setCodeInput] = useState("");
  // Server-resolved, never computed here: the fee preview below is built from
  // `applied.amount`, so a discount worked out in the browser would make the
  // channel picker quote a fee on a price nobody is being charged.
  const [applied, setApplied] = useState<DiscountPreview | null>(null);
  const [codeError, setCodeError] = useState<string | null>(null);

  const payable = applied ? applied.amount : (plan?.price ?? 0);
  // Free after a full discount: there is no channel to pick, and the server
  // settles the order outright.
  const needsChannel = gatewayLive && payable > 0;

  const reset = () => {
    setChannel(null);
    setCodeInput("");
    setApplied(null);
    setCodeError(null);
  };

  const close = () => {
    reset();
    onClose();
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

      onPaid();
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
      onBusyChange?.(false);
      if (!error) close();
    },
  });

  return (
    <Dialog open={!!plan} onOpenChange={(next) => !checkout.isPending && !next && close()}>
      <DialogContent>
        <DialogHeader
          title={gatewayLive ? "Pilih metode pembayaran" : "Konfirmasi pembelian"}
          description={plan ? `Beli paket ${plan.name}.` : undefined}
        />
        <DialogBody>
          {plan && (
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
                    onClick={() => discount.mutate({ plan, code: codeInput.trim() })}
                  >
                    {discount.isPending ? "Memeriksa…" : "Pakai"}
                  </Button>
                </div>
                {codeError && <p className="text-sm text-[var(--danger)]">{codeError}</p>}
              </div>

              <div className="grid gap-1 rounded-lg border p-3 text-sm">
                <div className="flex justify-between">
                  <span className="text-muted-foreground">Harga paket</span>
                  <span>{rupiah(plan.price)}</span>
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
          <Button variant="secondary" onClick={close} disabled={checkout.isPending}>
            Batal
          </Button>
          <Button
            disabled={(needsChannel && !channel) || checkout.isPending || !orgId}
            onClick={() => {
              if (!plan) return;
              onBusyChange?.(true);
              checkout.mutate({
                plan,
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
  );
}
