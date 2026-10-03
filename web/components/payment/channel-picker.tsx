"use client";

import { useQuery } from "@tanstack/react-query";
import { Check } from "lucide-react";

import { getPaymentChannels, type FeeAudience } from "@/lib/api/payments";
import { rupiah } from "@/lib/labels";
import { cn } from "@/lib/utils";
import { InfoHint } from "@/components/ui/info-hint";

/**
 * Fee-inclusive channel picker shown before Snap token creation. `amount` is
 * the price being paid — the fee breakdown for each channel is fetched for it,
 * so callers must not render this until `amount > 0`.
 *
 * `audience` says which of the three flows this payment is — buying tickets,
 * paying a team's registration fee, or buying a plan from us — because the
 * platform's own margin is set separately for each. It is required rather than
 * defaulted so a new checkout flow cannot quietly bill another flow's rate.
 *
 * `units` is the basket size. The ticket fee is charged per seat, so a cart of
 * three carries three of them — it is part of the query key because a quantity
 * change moves the total the buyer is quoted. The other two flows buy exactly
 * one thing, hence the default.
 */
export function ChannelPicker({
  amount,
  audience,
  units = 1,
  unitLabel = "tiket",
  value,
  onChange,
}: {
  amount: number;
  audience: FeeAudience;
  units?: number;
  /** Counted noun for the per-unit line; only rendered when `units > 1`. */
  unitLabel?: string;
  value: string | null;
  onChange: (channel: string) => void;
}) {
  const query = useQuery({
    queryKey: ["payment-channels", amount, audience, units],
    queryFn: () => getPaymentChannels(amount, audience, units),
    enabled: amount > 0,
  });

  if (query.isLoading) {
    return (
      <p className="text-sm text-muted-foreground">Memuat metode pembayaran…</p>
    );
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
                : "border-border hover:border-[var(--border-strong)]",
            )}
          >
            <div>
              <div className="flex items-center gap-2 font-medium">
                {c.label}
                {active && (
                  <Check className="h-4 w-4 text-[var(--brand-600)]" />
                )}
              </div>
              <p className="text-xs text-muted-foreground">
                Termasuk fee {rupiah(fee)}
              </p>
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
              <dt className="inline-flex items-center gap-1 text-muted-foreground">
                Biaya layanan
                {/* The one fee a buyer has no way to guess the shape of: it is
                    ours, not the bank's, and it multiplies with the quantity
                    while the gateway's charge next to it does not. */}
                <InfoHint label="Penjelasan biaya layanan">
                  <ServiceFeeExplainer
                    unit={selected.service_fee_unit}
                    unitLabel={unitLabel}
                    audience={audience}
                  />
                </InfoHint>
              </dt>
              <dd className="text-right">
                {selected.units > 1 && (
                  <span className="block text-xs text-muted-foreground tabular-nums">
                    {rupiah(selected.service_fee_unit)} &times; {selected.units}{" "}
                    {unitLabel}
                  </span>
                )}
                <span className="tabular-nums">
                  {rupiah(selected.service_fee)}
                </span>
              </dd>
            </div>
          )}
          {/* A channel's gateway fee can be toggled off in /admin/settings —
              saying "Biaya QRIS Rp 0" is worse than saying nothing. */}
          {selected.gateway_fee_base > 0 && (
            <div className="flex justify-between gap-4">
              <dt className="text-muted-foreground">Biaya {selected.label}</dt>
              <dd className="tabular-nums">
                {rupiah(selected.gateway_fee_base)}
              </dd>
            </div>
          )}
          {/* A channel may well be untaxed — saying "PPN Rp 0" is worse than
              saying nothing. */}
          {selected.gateway_tax > 0 && (
            <div className="flex justify-between gap-4">
              <dt className="text-muted-foreground">
                PPN {selected.tax_percent}%
              </dt>
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
        Poin dari penyedia pembayaran tidak boleh digunakan, dan PayLater
        dilarang — keduanya termasuk riba.
      </p>
    </div>
  );
}

/**
 * Shared copy for the two places a buyer meets the platform fee: the picker
 * above and the order page they land on afterwards. Written once because the
 * per-unit claim is the whole point — two copies would drift and one of them
 * would keep calling it per transaction.
 */
export function ServiceFeeExplainer({
  unit,
  unitLabel = "tiket",
  audience,
}: {
  /** Per-unit rate. Pass 0 when only the total is known (a stored order). */
  unit?: number;
  unitLabel?: string;
  /** Required, like ChannelPicker's: a default would let one of the three
      flows describe its fee with another flow's wording. */
  audience: FeeAudience;
}) {
  const per =
    audience === "organizer"
      ? "per pembelian paket"
      : audience === "registration"
        ? "per pendaftaran tim"
        : `per ${unitLabel}`;

  return (
    <>
      <span className="block">
        Biaya layanan dihitung{" "}
        <strong className="font-semibold text-foreground">{per}</strong>
        {/* The multiplier is deliberately NOT repeated here — the row this
            hint hangs off already spells out "Rp X x N tiket", and a second
            copy of the arithmetic would be a second thing to keep in step. */}
        {unit && unit > 0 ? <> sebesar {rupiah(unit)}</> : null}
      </span>
      <span className="mt-2 block">
        Biaya ini masuk ke floevent dan dipakai untuk pengembangan
        sistem/operasional tim. agar acara bisa terus berjalan lancar, aman, dan
        nyaman bagi semua pihak.
      </span>
    </>
  );
}
