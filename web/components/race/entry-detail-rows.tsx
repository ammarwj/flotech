"use client";

import { rupiah } from "@/lib/labels";
import { cn } from "@/lib/utils";

/**
 * One label-and-value line, the shape every block on the proof page uses.
 *
 * A component rather than a repeated flex div, because three lists on one page
 * written three times is how one of them ends up aligning differently — and
 * `tabular-nums` on the money ones is the kind of detail that gets dropped in
 * the copy nobody re-reads.
 */
export function DetailRow({
  label,
  value,
  strong,
  ruled,
  numeric,
}: {
  label: string;
  value: React.ReactNode;
  /** The answer, not a component of it. */
  strong?: boolean;
  /** Closes a group of lines — a subtotal, or a total. */
  ruled?: boolean;
  numeric?: boolean;
}) {
  return (
    <div
      className={cn(
        // The gap widens with the viewport, and both ends are measured against
        // the real page. At `gap-4` on a desktop card the longest labels nearly
        // touch their value while short ones sit a third of the card away, so
        // the rows read as different lists. At `gap-8` on a phone the label
        // loses the width it needs and "Nama Kontak Darurat" wraps mid-phrase
        // to buy space nothing uses.
        "flex items-baseline justify-between gap-4 py-1 text-sm sm:gap-8",
        ruled && "mt-1 border-t border-border pt-2",
        strong && "font-semibold"
      )}
    >
      <dt className="text-muted-foreground">{label}</dt>
      <dd className={cn("text-right", numeric && "tabular-nums")}>{value}</dd>
    </div>
  );
}

/**
 * The money breakdown: what was charged, split so each rate can be checked.
 *
 * Split and never lumped, the rule the e-ticket page set: one combined "Biaya
 * layanan" makes the rate impossible to check against what the channel picker
 * quoted.
 *
 * `paidTotal` is the running total once category moves are in. It only renders
 * when it DIFFERS from this entry's own bill, and the two labels say which is
 * which: the first is frozen (its receipt was issued before the move and is
 * rendered from the row), the second is what actually left the runner's
 * account. Both are true, which is exactly why neither may go unlabelled.
 */
export function PriceBreakdown({
  price,
  serviceFee,
  gatewayFee,
  gatewayTax,
  grossAmount,
  paidTotal,
}: {
  price: number;
  serviceFee: number;
  gatewayFee: number;
  gatewayTax: number;
  grossAmount: number;
  paidTotal: number;
}) {
  const moved = Math.abs(paidTotal - grossAmount) > 0.009;
  const taxed = gatewayTax > 0;

  return (
    <dl>
      <DetailRow label="Biaya pendaftaran" value={rupiah(price)} numeric />
      {serviceFee > 0 && <DetailRow label="Biaya layanan" value={rupiah(serviceFee)} numeric />}
      {gatewayFee > 0 && (
        <DetailRow
          label={taxed ? "Biaya payment gateway" : "Biaya pembayaran"}
          value={rupiah(gatewayFee - gatewayTax)}
          numeric
        />
      )}
      {taxed && <DetailRow label="PPN" value={rupiah(gatewayTax)} numeric />}
      <DetailRow
        label={moved ? "Total awal" : "Total"}
        value={rupiah(grossAmount)}
        ruled
        strong={!moved}
        numeric
      />
      {moved && (
        <DetailRow label="Total dibayar" value={rupiah(paidTotal)} ruled strong numeric />
      )}
    </dl>
  );
}
