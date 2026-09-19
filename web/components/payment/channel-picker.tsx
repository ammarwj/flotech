"use client";

import { useQuery } from "@tanstack/react-query";
import { Check } from "lucide-react";

import { getPaymentChannels, type FeeAudience } from "@/lib/api/payments";
import { rupiah } from "@/lib/labels";
import { cn } from "@/lib/utils";

/**
 * Fee-inclusive channel picker shown before Snap token creation. `amount` is
 * the price being paid — the fee breakdown for each channel is fetched for it,
 * so callers must not render this until `amount > 0`.
 *
 * `audience` says which side of the platform this payment sits on, because the
 * platform's own margin is set separately for each; it is required rather than
 * defaulted so a new checkout flow cannot quietly bill the other side's rate.
 */
export function ChannelPicker({
  amount,
  audience,
  value,
  onChange,
}: {
  amount: number;
  audience: FeeAudience;
  value: string | null;
  onChange: (channel: string) => void;
}) {
  const query = useQuery({
    queryKey: ["payment-channels", amount, audience],
    queryFn: () => getPaymentChannels(amount, audience),
    enabled: amount > 0,
  });

  if (query.isLoading) {
    return <p className="text-sm text-muted-foreground">Memuat metode pembayaran…</p>;
  }

  const channels = query.data ?? [];
  const selected = channels.find((c) => c.channel === value) ?? null;

  return (
    <div className="grid gap-2">
      <span className="text-sm font-medium">Metode pembayaran</span>
      {channels.map((c) => {
        const active = value === c.channel;
        const fee = c.gateway_fee + c.service_fee;
        return (
          <button
            key={c.channel}
            type="button"
            onClick={() => onChange(c.channel)}
            className={cn(
              "flex w-full items-center justify-between rounded-xl border p-3 text-left transition-colors",
              active
                ? "border-[var(--brand-600)] bg-[var(--tint)]"
                : "border-border hover:border-[var(--border-strong)]"
            )}
          >
            <div>
              <div className="flex items-center gap-2 font-medium">
                {c.label}
                {active && <Check className="h-4 w-4 text-[var(--brand-600)]" />}
              </div>
              <p className="text-xs text-muted-foreground">Termasuk fee {rupiah(fee)}</p>
            </div>
            <span className="shrink-0 font-semibold">{rupiah(c.total)}</span>
          </button>
        );
      })}

      {/* Only once a channel is picked: the fee is per channel, so before that
          there is no single breakdown to show. */}
      {selected && (
        <dl className="mt-1 grid gap-1.5 rounded-xl border border-border p-3 text-sm">
          <div className="flex justify-between gap-4">
            <dt className="text-muted-foreground">Harga</dt>
            <dd className="tabular-nums">{rupiah(amount)}</dd>
          </div>
          {selected.service_fee > 0 && (
            <div className="flex justify-between gap-4">
              <dt className="text-muted-foreground">Biaya layanan</dt>
              <dd className="tabular-nums">{rupiah(selected.service_fee)}</dd>
            </div>
          )}
          {/* A channel's gateway fee can be toggled off in /admin/settings —
              saying "Biaya QRIS Rp 0" is worse than saying nothing. */}
          {selected.gateway_fee_base > 0 && (
            <div className="flex justify-between gap-4">
              <dt className="text-muted-foreground">Biaya {selected.label}</dt>
              <dd className="tabular-nums">{rupiah(selected.gateway_fee_base)}</dd>
            </div>
          )}
          {/* A channel may well be untaxed — saying "PPN Rp 0" is worse than
              saying nothing. */}
          {selected.gateway_tax > 0 && (
            <div className="flex justify-between gap-4">
              <dt className="text-muted-foreground">PPN {selected.tax_percent}%</dt>
              <dd className="tabular-nums">{rupiah(selected.gateway_tax)}</dd>
            </div>
          )}
          <div className="mt-1 flex justify-between gap-4 border-t border-border pt-2 font-semibold">
            <dt>Total bayar</dt>
            <dd className="tabular-nums">{rupiah(selected.total)}</dd>
          </div>
        </dl>
      )}

      <p className="text-xs text-muted-foreground">
        Poin dari penyedia pembayaran tidak boleh digunakan, dan PayLater dilarang —
        keduanya termasuk riba.
      </p>
    </div>
  );
}
