"use client";

import { useEffect, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { format, parseISO } from "date-fns";
import { id as idLocale } from "date-fns/locale/id";
import { ScanLine, Search } from "lucide-react";

import { getCheckins } from "@/lib/api/tickets";
import { dayChipLabel, dayFullLabel } from "@/lib/match-dates";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { EmptyState } from "@/components/shared/empty-state";

const fmtTime = (iso: string | null) =>
  iso ? format(parseISO(iso), "d MMM yyyy, HH:mm", { locale: idLocale }) : "—";

/**
 * The same instant without the year, for the phone column.
 *
 * The year is the one part of that stamp nobody at the gate is reading: a
 * check-in log is looked at during the event, and "2026" costs the width that
 * the holder's name needs.
 */
const fmtTimeShort = (iso: string | null) =>
  iso ? format(parseISO(iso), "d MMM, HH:mm", { locale: idLocale }) : "—";

/**
 * The gate log: every admitted ticket, newest first.
 *
 * Paginated against its own endpoint rather than rendered from the twenty rows
 * the report payload used to carry. Two things were wrong with the tail: an
 * event admitting two thousand people has a log nobody can reach past row 20,
 * and the three columns were laid out with `justify-between`, so every row's
 * middle cell sat wherever its neighbours' widths left it — the category
 * wandered left and right down the list.
 *
 * A table, so the columns are columns. The narrow screen drops the two it can
 * fold under the name instead of scrolling sideways: at the gate this is read
 * on a phone, and a horizontal scrollbar hides the timestamp that is the whole
 * point of the row.
 *
 * `table-fixed` is load-bearing, not tidiness. An auto-layout table sizes its
 * columns from their content, so `truncate` on the name cell has nothing to
 * truncate against — "SMK BINA SEJAHTERA 3 KOTA BOGOR" widens the table past
 * the card and pushes the time column off the screen, which is exactly what it
 * did. Fixed layout gives the name whatever the sized columns beside it leave,
 * and the ellipsis finally has a width to appear at.
 */
export function CheckinLog({
  orgId,
  eventId,
  /** The event's days, for the filter. Empty when nothing sells by the day. */
  days,
}: {
  orgId: string;
  eventId: string;
  days: string[];
}) {
  const [search, setSearch] = useState("");
  const [q, setQ] = useState("");
  const [date, setDate] = useState("");
  const [page, setPage] = useState(1);

  // Debounced, like the buyer list: the filtering is the server's, so every
  // keystroke would otherwise be a round trip.
  useEffect(() => {
    const t = setTimeout(() => {
      setQ(search);
      setPage(1);
    }, 350);
    return () => clearTimeout(t);
  }, [search]);

  const logQuery = useQuery({
    queryKey: ["checkins", orgId, eventId, { q, date, page }],
    queryFn: () =>
      getCheckins(orgId, eventId, { q: q || undefined, date: date || undefined, page }),
    // Keeps the current page on screen while the next one loads, so the filter
    // row does not jump as the list unmounts under it.
    placeholderData: (prev) => prev,
  });

  const items = logQuery.data?.items ?? [];
  const meta = logQuery.data?.meta;
  const filtered = !!(q || date);
  // The day column is worth a column only when days exist at all — a `none`
  // event would get a header above a column of dashes.
  const showDays = days.length > 0;

  if (logQuery.isLoading) {
    return (
      <div className="grid gap-2">
        {[0, 1, 2].map((i) => (
          <Skeleton key={i} className="h-14 w-full rounded-xl" />
        ))}
      </div>
    );
  }

  // Nothing scanned yet and nothing to filter: the filter row would be a set of
  // controls over an empty table.
  if (!filtered && meta?.total === 0) {
    return (
      <EmptyState
        icon={ScanLine}
        title="Belum ada check-in"
        description="Penonton yang masuk lewat pemindaian QR atau check-in di loket akan tercatat di sini."
      />
    );
  }

  return (
    <div className="grid gap-3">
      <div className="grid gap-2 sm:grid-cols-[1fr_auto]">
        <div className="relative">
          <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
          <Input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Cari nama pemegang tiket…"
            className="pl-9"
          />
        </div>
        {showDays && (
          <Select
            aria-label="Hari hadir"
            value={date}
            onChange={(e) => {
              setDate(e.target.value);
              setPage(1);
            }}
            className="sm:w-56"
          >
            <option value="">Semua hari</option>
            {days.map((d) => (
              <option key={d} value={d}>
                {dayFullLabel(d)}
              </option>
            ))}
          </Select>
        )}
      </div>

      {items.length === 0 ? (
        <EmptyState
          icon={Search}
          title="Tidak ada check-in yang cocok"
          description="Coba ubah kata kunci atau pilih hari yang lain."
        />
      ) : (
        <div className="overflow-hidden rounded-xl border border-border">
          {/* Every column but the name carries an explicit width, because under
              `table-fixed` the name's share is whatever they leave — a column
              left to size itself takes an equal share and the name gets a
              quarter of the row. */}
          <table className="w-full table-fixed text-sm">
            <thead>
              <tr className="bg-[var(--surface-2)] text-xs uppercase tracking-wide text-muted-foreground">
                <th className="px-3 py-3 text-left font-semibold">Pemegang</th>
                <th className="hidden w-40 px-3 py-3 text-left font-semibold sm:table-cell lg:w-56">
                  Kategori
                </th>
                {showDays && (
                  <th className="hidden w-32 px-3 py-3 text-left font-semibold md:table-cell">
                    Hari
                  </th>
                )}
                {/* Narrower on a phone, where the year is dropped with it. */}
                <th className="w-28 px-3 py-3 text-right font-semibold sm:w-40">Waktu</th>
              </tr>
            </thead>
            <tbody>
              {items.map((c) => (
                <tr key={c.id} className="border-t border-border align-top">
                  <td className="px-3 py-3">
                    <div className="truncate font-medium">{c.holder_name ?? "Tanpa nama"}</div>
                    {/* What the folded columns say, under the name. */}
                    <div className="truncate text-xs text-muted-foreground sm:hidden">
                      {[c.category, c.event_date ? dayChipLabel(c.event_date) : null]
                        .filter(Boolean)
                        .join(" · ") || "—"}
                    </div>
                    {showDays && c.event_date && (
                      <div className="hidden truncate text-xs text-muted-foreground sm:block md:hidden">
                        {dayChipLabel(c.event_date)}
                      </div>
                    )}
                  </td>
                  <td className="hidden px-3 py-3 text-muted-foreground sm:table-cell">
                    <span className="block truncate">{c.category ?? "—"}</span>
                  </td>
                  {showDays && (
                    <td className="hidden px-3 py-3 text-muted-foreground md:table-cell">
                      <span className="block truncate">
                        {c.event_date ? dayChipLabel(c.event_date) : "—"}
                      </span>
                    </td>
                  )}
                  <td className="whitespace-nowrap px-3 py-3 text-right text-muted-foreground">
                    <span className="sm:hidden">{fmtTimeShort(c.used_at)}</span>
                    <span className="hidden sm:inline">{fmtTime(c.used_at)}</span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {/* `flex-wrap` + `ml-auto` rather than `justify-between` alone: the count
          string is long and both buttons are `whitespace-nowrap`, so at phone
          width the row would overflow instead of wrapping. */}
      {meta && meta.last_page > 1 && (
        <div className="flex flex-wrap items-center gap-3 text-sm">
          <span className="text-muted-foreground">
            Halaman {meta.page} dari {meta.last_page} · {meta.total} check-in
          </span>
          <div className="ml-auto flex gap-2">
            <Button
              size="sm"
              variant="outline"
              onClick={() => setPage((p) => Math.max(1, p - 1))}
              disabled={meta.page <= 1}
            >
              Sebelumnya
            </Button>
            <Button
              size="sm"
              variant="outline"
              onClick={() => setPage((p) => p + 1)}
              disabled={meta.page >= meta.last_page}
            >
              Berikutnya
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}
