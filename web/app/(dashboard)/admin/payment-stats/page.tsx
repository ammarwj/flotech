"use client";

import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Banknote, CreditCard, Receipt, Wallet } from "lucide-react";

import { getAdminPaymentStats } from "@/lib/api/admin-wallet";
import { angka, rupiah } from "@/lib/labels";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { DatePicker } from "@/components/ui/date-picker";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { EventCombobox, type EventOption } from "@/components/admin/event-combobox";
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
      <div className="h-1.5 min-w-0 flex-1 overflow-hidden rounded-full bg-[var(--tint)] sm:w-24 sm:flex-none">
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
    <Card className="mt-4 p-4 sm:p-6">
      <h3 className="text-base font-bold" style={{ fontFamily: "var(--font-display)" }}>
        {title}
      </h3>
      <p className="mt-1 text-sm text-muted-foreground">{description}</p>

      {loading ? (
        <Skeleton className="mt-4 h-32 w-full" />
      ) : rows.length ? (
        <>
          {/* Two layouts rather than a scrolling table: six money columns do not
              fit any phone, and a horizontally scrolled table hides the figure
              the row exists for (`Total ditagih`) off-screen behind a gesture
              nothing on screen advertises. Each row's own numbers are labelled
              inline below md, so no header has to stay in view for them to be
              readable. */}
          <ul className="mt-4 grid gap-2 md:hidden">
            {rows.map((row) => (
              <li key={row.key} className="rounded-xl bg-[var(--bg-alt)] p-3">
                <div className="flex items-baseline justify-between gap-3">
                  <span className="min-w-0 break-words font-medium">{row.name}</span>
                  <span className="shrink-0 font-semibold tabular-nums">
                    {rupiah(row.sums.gross)}
                  </span>
                </div>
                <dl className="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-xs text-muted-foreground">
                  <div className="flex justify-between gap-2">
                    <dt>Transaksi</dt>
                    <dd className="tabular-nums">{angka(row.sums.count)}</dd>
                  </div>
                  <div className="flex justify-between gap-2">
                    <dt>Nilai jual</dt>
                    <dd className="tabular-nums">{rupiah(row.sums.amount)}</dd>
                  </div>
                  <div className="col-span-2 flex justify-between gap-2">
                    <dt>Biaya pembeli</dt>
                    <dd className="tabular-nums">
                      {rupiah(row.sums.gateway_fee + row.sums.service_fee)}
                    </dd>
                  </div>
                </dl>
                <div className="mt-2">
                  <Share value={row.sums.gross} total={total} />
                </div>
              </li>
            ))}
          </ul>

          <div className="mt-4 hidden overflow-x-auto md:block">
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
          </div>
        </>
      ) : (
        <p className="py-6 text-center text-sm text-muted-foreground">{empty}</p>
      )}
    </Card>
  );
}

export default function AdminPaymentStatsPage() {
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  // The whole pair, not just the id: the event search is keyed by name, so the
  // chosen event's label cannot be re-derived from an id without a second
  // request — and the notice below the filters has to be able to name it.
  const [event, setEvent] = useState<EventOption | null>(null);
  const filtered = Boolean(from || to || event);

  const query = useQuery({
    queryKey: ["admin-payment-stats", from, to, event?.id ?? null],
    queryFn: () =>
      getAdminPaymentStats({
        from: from || undefined,
        to: to || undefined,
        event_id: event?.id,
      }),
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

      <Card className="mb-6 p-4 sm:p-5">
        {/* One column on a phone: a date pair side by side at 375px leaves each
            picker narrower than the date it has to print, and the event box is
            a search field that needs the full width to be usable at all. */}
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-[1fr_1fr_minmax(0,1.4fr)] lg:items-end">
          <div className="grid gap-1.5">
            <Label htmlFor="paid-from" className="text-xs text-muted-foreground">
              Dibayar dari
            </Label>
            <DatePicker
              id="paid-from"
              value={from}
              max={to || undefined}
              onChange={setFrom}
              clearable
              placeholder="Awal mula"
            />
          </div>
          <div className="grid gap-1.5">
            <Label htmlFor="paid-to" className="text-xs text-muted-foreground">
              Sampai
            </Label>
            <DatePicker
              id="paid-to"
              value={to}
              min={from || undefined}
              onChange={setTo}
              clearable
              placeholder="Hari ini"
            />
          </div>
          {/* Full width below lg: at the sm two-column step the event box would
              sit in half a card next to a date, and a search field that cannot
              show the name it matched is a filter you have to trust blind. */}
          <div className="grid gap-1.5 sm:col-span-2 lg:col-span-1">
            <Label htmlFor="paid-event" className="text-xs text-muted-foreground">
              Event
            </Label>
            <EventCombobox id="paid-event" value={event} onChange={setEvent} />
          </div>
        </div>

        {/* Said out loud: a dashboard that silently covers all time reads as
            "this is everything" when a filter is still applied, and vice versa.
            The event half is spelled separately because its consequence is one
            nobody would guess from the controls — see the API client's note on
            unclaimed plan credits. */}
        <div className="mt-3 flex flex-wrap items-start justify-between gap-3">
          <p className="min-w-0 text-xs text-muted-foreground">
            {event ? (
              <>
                Hanya pembayaran event <span className="font-medium">{event.name}</span>
                {from || to ? " dalam rentang tanggal di atas" : ""}. Kredit paket yang dibeli
                tapi belum dipakai tidak ikut terhitung — kreditnya belum menempel ke event
                mana pun.
              </>
            ) : from || to ? (
              "Hanya pembayaran yang lunas dalam rentang tanggal di atas, seluruh event."
            ) : (
              "Seluruh pembayaran yang pernah lunas, sepanjang waktu."
            )}
          </p>
          {filtered && (
            <Button
              variant="ghost"
              size="sm"
              onClick={() => {
                setFrom("");
                setTo("");
                setEvent(null);
              }}
            >
              Reset filter
            </Button>
          )}
        </div>
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
          title={filtered ? "Tidak ada pembayaran yang cocok" : "Belum ada pembayaran lunas"}
          description={
            event
              ? "Event ini belum punya pembayaran lunas. Coba lepas filter event atau lebarkan rentang tanggalnya."
              : filtered
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

          <Card className="mt-4 p-4 sm:p-6">
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
