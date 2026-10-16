"use client";

import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Banknote, CreditCard, Receipt, Wallet } from "lucide-react";

import { getAdminPaymentStats } from "@/lib/api/admin-wallet";
import { angka, rupiah } from "@/lib/labels";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { StatCard } from "@/components/shared/stat-card";
import { EmptyState } from "@/components/shared/empty-state";
import type { AdminPaymentTotals } from "@/types/api";

/**
 * What each rail is, in the organizer's own words.
 *
 * Keyed by the server's `by_source` keys so a rail added there shows up as its
 * raw key rather than vanishing — a missing row would read as "nobody paid for
 * plans", which is a very different statement from "we have no label for it".
 */
const SOURCE_LABELS: Record<string, { label: string; hint: string }> = {
  ticket_order: { label: "Tiket", hint: "Penonton membeli tiket dari organizer" },
  team: { label: "Pendaftaran tim", hint: "Biaya pendaftaran yang dibayar peserta" },
  race_entry: { label: "Entry lomba", hint: "Pelari mendaftar fun run / race" },
  event_plan_order: { label: "Paket event", hint: "Organizer membeli paket dari flo-event" },
};

/**
 * The bucket the server reports for a payment with no Midtrans instrument.
 *
 * Spelled once, and never filtered out: it covers manual transfers, box-office
 * cash, free orders and anything settled before the column existed — all of it
 * real revenue. Dropping the row would make the table's shape change from
 * request to request.
 */
const UNKNOWN = "unknown";

/**
 * Values in `payment_type` that our own code writes, not Midtrans.
 *
 * Labelling these is the opposite of hardcoding Midtrans' catalogue — that list
 * belongs to them and is deliberately shown raw, while these three are ours and
 * are already in the column on real rows: `manual_transfer` from a super admin
 * approving a plan receipt, `legacy_migration` from `events:backfill-plan`
 * (money that never moved, hence Rp 0), and UNKNOWN from the server. Rendered
 * raw they read as instruments a buyer chose, which none of them is.
 *
 * Anything not listed falls through to its own name — a Midtrans instrument
 * added tomorrow must show up as itself rather than go blank.
 */
const OWN_TYPE_LABELS: Record<string, string> = {
  [UNKNOWN]: "Tanpa gateway",
  manual_transfer: "Transfer manual (acc admin)",
  legacy_migration: "Migrasi data lama",
};

/** A share bar, so a long tail is readable without reaching for a chart. */
function Share({ value, total }: { value: number; total: number }) {
  const pct = total > 0 ? (value / total) * 100 : 0;

  return (
    <div className="flex items-center gap-2">
      <div className="h-1.5 w-16 overflow-hidden rounded-full bg-[var(--tint)] sm:w-24">
        <div
          className="h-full rounded-full bg-[var(--brand-600)]"
          style={{ width: `${Math.max(pct, pct > 0 ? 2 : 0)}%` }}
        />
      </div>
      <span className="w-10 shrink-0 text-right text-xs tabular-nums text-muted-foreground">
        {pct.toFixed(0)}%
      </span>
    </div>
  );
}

/**
 * One breakdown table. Both cuts of the data have the same columns, so they
 * share one component rather than two copies that drift.
 *
 * `total` is the figure every share is computed against, and it is passed in
 * from `totals` rather than re-summed from `rows`: the two are the same number
 * today, and summing here would quietly start answering a different question
 * the moment the server ever pages or filters a breakdown.
 */
function Breakdown({
  title,
  description,
  nameHeader,
  rows,
  total,
  loading,
  empty,
}: {
  title: string;
  description: string;
  nameHeader: string;
  rows: { key: string; name: string; sums: AdminPaymentTotals }[];
  total: number;
  loading: boolean;
  empty: string;
}) {
  return (
    <Card className="mt-4 p-6">
      <h3 className="text-base font-bold" style={{ fontFamily: "var(--font-display)" }}>
        {title}
      </h3>
      <p className="mt-1 text-sm text-muted-foreground">{description}</p>

      <div className="mt-4 overflow-x-auto">
        {loading ? (
          <Skeleton className="h-32 w-full" />
        ) : rows.length ? (
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-border text-left text-xs text-muted-foreground">
                <th className="pb-2 font-medium">{nameHeader}</th>
                <th className="pb-2 text-right font-medium">Transaksi</th>
                <th className="pb-2 text-right font-medium">Nilai jual</th>
                <th className="pb-2 text-right font-medium">Biaya pembeli</th>
                <th className="pb-2 text-right font-medium">Total ditagih</th>
                <th className="pb-2 text-right font-medium">Porsi</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                <tr key={row.key} className="border-b border-border last:border-0">
                  <td className="py-2.5 pr-3 font-medium">{row.name}</td>
                  <td className="py-2.5 text-right tabular-nums text-muted-foreground">
                    {angka(row.sums.count)}
                  </td>
                  <td className="py-2.5 text-right tabular-nums">{rupiah(row.sums.amount)}</td>
                  <td className="py-2.5 text-right tabular-nums text-muted-foreground">
                    {rupiah(row.sums.gateway_fee + row.sums.service_fee)}
                  </td>
                  <td className="py-2.5 text-right font-semibold tabular-nums">
                    {rupiah(row.sums.gross)}
                  </td>
                  <td className="py-2.5 pl-3">
                    <div className="flex justify-end">
                      <Share value={row.sums.gross} total={total} />
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : (
          <p className="py-6 text-center text-sm text-muted-foreground">{empty}</p>
        )}
      </div>
    </Card>
  );
}

export default function AdminPaymentStatsPage() {
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const filtered = Boolean(from || to);

  const query = useQuery({
    queryKey: ["admin-payment-stats", from, to],
    queryFn: () => getAdminPaymentStats({ from: from || undefined, to: to || undefined }),
  });

  const data = query.data;
  // The server's own roll-up, not a sum over the breakdowns: it already
  // guarantees the cards and the tables under them cannot disagree, and
  // re-adding them here would be the second reader that makes them able to.
  const totals = data?.totals;
  const gross = totals?.gross ?? 0;

  const typeRows = (data?.by_payment_type ?? []).map((row) => ({
    key: row.payment_type,
    name: OWN_TYPE_LABELS[row.payment_type] ?? row.payment_type,
    sums: row,
  }));

  // Labels come from the server, which reads the same config the checkout
  // picker does — a copy here would drift the day a channel is renamed.
  const channelRows = (data?.by_channel ?? []).map((row) => ({
    key: row.payment_channel,
    name: row.label,
    sums: row,
  }));

  const sourceRows = Object.entries(data?.by_source ?? {});

  return (
    <div>
      <PageHeader
        title={
          <span className="inline-flex items-center gap-2">
            <Receipt className="h-5 w-5 text-[var(--brand-600)]" />
            Perhitungan Pembayaran
          </span>
        }
        description="Seluruh pembayaran yang sudah lunas di platform, dirinci per instrumen Midtrans, channel, dan jenis pemasukan."
      />

      <Card className="mb-6 p-5">
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 lg:items-end">
          <label className="grid gap-1 text-xs text-muted-foreground">
            Dibayar dari
            <Input
              type="date"
              value={from}
              max={to || undefined}
              onChange={(e) => setFrom(e.target.value)}
            />
          </label>
          <label className="grid gap-1 text-xs text-muted-foreground">
            sampai
            <Input
              type="date"
              value={to}
              min={from || undefined}
              onChange={(e) => setTo(e.target.value)}
            />
          </label>
          {filtered && (
            <Button
              variant="ghost"
              size="sm"
              className="justify-self-start"
              onClick={() => {
                setFrom("");
                setTo("");
              }}
            >
              Reset filter
            </Button>
          )}
        </div>
        {/* Said out loud: a dashboard that silently covers all time reads as
            "this is everything" when a filter is still applied, and vice versa. */}
        <p className="mt-3 text-xs text-muted-foreground">
          {filtered
            ? "Hanya pembayaran yang lunas dalam rentang tanggal di atas."
            : "Seluruh pembayaran yang pernah lunas, sepanjang waktu."}
        </p>
      </Card>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard
          label="Total ditagih"
          value={rupiah(gross)}
          icon={Banknote}
          loading={query.isPending}
          hint={`${angka(totals?.count ?? 0)} transaksi lunas`}
        />
        <StatCard
          label="Nilai jual"
          value={rupiah(totals?.amount ?? 0)}
          icon={Wallet}
          color="var(--accent-green)"
          loading={query.isPending}
          hint="Harga tiket, pendaftaran, dan paket — sebelum biaya pembeli"
        />
        <StatCard
          label="Biaya gateway"
          value={rupiah(totals?.gateway_fee ?? 0)}
          icon={CreditCard}
          color="var(--accent-purple)"
          loading={query.isPending}
          hint="Dibayar pembeli; PPN sudah termasuk di dalamnya"
        />
        <StatCard
          label="Biaya layanan"
          value={rupiah(totals?.service_fee ?? 0)}
          icon={Receipt}
          color="var(--accent-amber)"
          loading={query.isPending}
          hint="Margin platform, dibayar pembeli di atas harga jual"
        />
      </div>

      {!query.isPending && totals?.count === 0 ? (
        <EmptyState
          className="mt-6"
          icon={Receipt}
          title={filtered ? "Tidak ada pembayaran di rentang ini" : "Belum ada pembayaran lunas"}
          description={
            filtered
              ? "Coba lebarkan rentang tanggalnya."
              : "Angka akan muncul begitu ada tiket, pendaftaran, atau paket yang lunas."
          }
        />
      ) : (
        <>
          <Breakdown
            title="Per instrumen pembayaran"
            description="Apa yang benar-benar dipakai pembeli, dilaporkan Midtrans saat pembayaran settle. “Tanpa gateway” mencakup transfer manual tiket/pendaftaran, tunai di loket, dan pesanan gratis; dua baris lainnya ditulis sistem sendiri, bukan Midtrans."
            nameHeader="Instrumen"
            rows={typeRows}
            total={gross}
            loading={query.isPending}
            empty="Belum ada pembayaran yang tercatat instrumennya."
          />

          <Breakdown
            title="Per channel"
            description="Grup tarif yang dipilih pembeli sebelum membayar — dasar perhitungan biaya gateway. Satu channel bisa memuat beberapa instrumen: QRIS mencakup qris, gopay, dan shopeepay."
            nameHeader="Channel"
            rows={channelRows}
            total={gross}
            loading={query.isPending}
            empty="Belum ada pembayaran lewat payment gateway."
          />

          <Card className="mt-4 p-6">
            <h3 className="text-base font-bold" style={{ fontFamily: "var(--font-display)" }}>
              Per jenis pemasukan
            </h3>
            <p className="mt-1 text-sm text-muted-foreground">
              Tiket, pendaftaran tim, dan entry lomba dikumpulkan{" "}
              <span className="font-medium">untuk</span> organizer dan masuk ke dompetnya; paket
              event adalah pendapatan flo-event sendiri.
            </p>

            {/* Column count and skeleton count both come off SOURCE_LABELS, so a
                rail added to the server shows up here without a second number
                to remember — a hand-counted grid silently drops the new one
                onto a row of its own. */}
            <div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
              {query.isPending
                ? Object.keys(SOURCE_LABELS).map((key) => (
                    <Skeleton key={key} className="h-24 rounded-xl" />
                  ))
                : sourceRows.map(([key, sums]) => (
                    <div key={key} className="rounded-xl bg-[var(--bg-alt)] p-4">
                      <p className="text-sm font-semibold">{SOURCE_LABELS[key]?.label ?? key}</p>
                      <p
                        className="mt-1 text-xl font-extrabold tabular-nums"
                        style={{ fontFamily: "var(--font-display)" }}
                      >
                        {rupiah(sums.gross)}
                      </p>
                      <p className="mt-1 text-xs text-muted-foreground">
                        {angka(sums.count)} transaksi
                        {sums.count > 0 && gross > 0
                          ? ` · ${((sums.gross / gross) * 100).toFixed(0)}% dari total`
                          : ""}
                      </p>
                      {SOURCE_LABELS[key] && (
                        <p className="mt-2 text-xs text-muted-foreground">
                          {SOURCE_LABELS[key].hint}
                        </p>
                      )}
                    </div>
                  ))}
            </div>
          </Card>
        </>
      )}
    </div>
  );
}
