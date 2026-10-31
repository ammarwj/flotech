"use client";

import { Fragment, useEffect, useMemo, useState, type ReactNode } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { useQuery } from "@tanstack/react-query";
import { ScanLine, Search, Users } from "lucide-react";

import { getRaceEntries, type RaceEntryFilter } from "@/lib/api/race";
import { getEvent } from "@/lib/api/events";
import { isExportEnabled } from "@/lib/plan";
import { rupiah } from "@/lib/labels";
import { cn } from "@/lib/utils";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { RaceEntryStatusBadge } from "@/components/shared/status-badge";
import { useUrlState } from "@/lib/hooks/use-url-state";
import { EmptyState } from "@/components/shared/empty-state";
import { RaceRouteGuard } from "@/components/event/race-route-guard";
import {
  DetailToggle,
  RaceEntryDetail,
} from "@/components/event/race-entry-detail";
import { ExportButtons } from "@/components/event/export-buttons";
import { schemaOf } from "@/lib/registration-form";

/**
 * The status pills, in the order a runner's entry moves through them.
 *
 * `awaiting_verification` sits between "menunggu pembayaran" and "lunas"
 * because that is where it belongs in the story — proof is in, nobody has ruled
 * on it — even though it is not a `status` value at all but a flag derived from
 * the proof columns. The cards already count it, so leaving it out would print a
 * number with no way to reach the rows behind it. The API answers it with the
 * same HasManualPayment scope the verification queue uses.
 */
const FILTERS: [RaceEntryFilter, string][] = [
  ["all", "Semua"],
  ["pending", "Menunggu Pembayaran"],
  ["awaiting_verification", "Menunggu Verifikasi"],
  ["paid", "Lunas"],
  ["cancelled", "Dibatalkan"],
  ["expired", "Kedaluwarsa"],
  ["refunded", "Dikembalikan"],
];

const isFilter = (v: string | null): v is RaceEntryFilter =>
  !!v && FILTERS.some(([key]) => key === v);

/**
 * The runners who entered a fun run, and what the event has taken for them.
 *
 * The categories and the sale windows they are priced against are set up on the
 * event's own Kelola page; this route is the list, which is the half an
 * organizer reads every day rather than once in the first week.
 *
 * Every filter lives in the query string — the status pill, the category, the
 * search box — for the reason the buyer list keeps its there: this page is kept
 * open and reloaded all day, and a refresh that silently drops back to "Semua"
 * shows a list answering a different question than the one on screen a second
 * ago. It also makes a filtered view linkable.
 */
export default function EventRacePage() {
  const params = useParams<{ id: string }>();
  const eventId = params.id;
  const { orgId } = useActiveOrg();

  const { params: sp, setParams } = useUrlState();

  const page = Math.max(1, Number(sp.get("page")) || 1);
  const q = sp.get("q") ?? "";
  const category = sp.get("category") ?? "";
  // An unknown value falls back rather than being sent on — the API rejects it,
  // and a 422 under a pill nobody can see reads as a broken page.
  const rawFilter = sp.get("status");
  const filter: RaceEntryFilter = isFilter(rawFilter) ? rawFilter : "all";

  /** Any filter change resets the page — page 3 of a new filter is usually empty. */
  const pick = (next: Record<string, string | undefined>) =>
    setParams({ ...next, page: undefined });

  // Whether anything is narrowing the list, which is what tells an empty
  // response "nobody matches" apart from "nobody has entered".
  const filtered = filter !== "all" || !!category || !!q;

  const [search, setSearch] = useState(q);
  // Which rows are expanded. A set rather than a single id: comparing two
  // entrants is the reason an organizer opens one in the first place, and a
  // detail that closes the previous row makes that the one thing it cannot do.
  const [openRows, setOpenRows] = useState<Set<string>>(new Set());

  const setPage = (p: number) =>
    setParams({ page: p > 1 ? String(p) : undefined });

  // Debounced so a request does not go out per keystroke — the filtering is the
  // server's. The equality guard is load-bearing now that `q` comes from the
  // URL: on mount the box is seeded from it, and writing that same value back
  // would reset `page` on every render.
  useEffect(() => {
    if (search === q) return;
    const t = setTimeout(() => {
      setParams({ q: search || undefined, page: undefined });
    }, 350);
    return () => clearTimeout(t);
  }, [search, q, setParams]);

  const toggleRow = (id: string) =>
    setOpenRows((prev) => {
      const next = new Set(prev);
      if (next.has(id)) {
        next.delete(id);
      } else {
        next.add(id);
      }
      return next;
    });

  const eventQuery = useQuery({
    queryKey: ["event", orgId, eventId],
    queryFn: () => getEvent(orgId!, eventId),
    enabled: !!orgId,
  });

  // What this event asked for, which is what the detail panel renders the
  // answers against. An event that is still loading reads as "nothing defined",
  // so the panel shows the built-in columns and grows the rest once it arrives.
  const schema = schemaOf(eventQuery.data);

  const entriesQuery = useQuery({
    // `q` and `page` from the URL, not the input's own state: the debounced
    // effect above writes them there, and keying on the raw box would fire a
    // request per keystroke.
    queryKey: ["race-entries", orgId, eventId, { filter, category, q, page }],
    queryFn: () =>
      getRaceEntries(orgId!, eventId, {
        status: filter === "all" ? undefined : filter,
        category_id: category || undefined,
        search: q || undefined,
        page,
        // 25, not 50: every row can expand into a detail panel, and a hundred
        // of those in one DOM is what the pagination is for.
        per_page: 25,
      }),
    enabled: !!orgId,
    // The current page stays on screen while the next loads — without it every
    // page turn blanks back to a skeleton and the table jumps.
    placeholderData: (prev) => prev,
  });

  /**
   * The pager's figures. `count` is how many rows the current filter matches —
   * NOT the event's total, which is the cards' subject below and ignores the
   * status pill. Reading one for both would have "Dibatalkan" announce the
   * whole event as cancelled.
   */
  const meta = useMemo(
    () => entriesQuery.data?.meta ?? { page: 1, last_page: 1, count: 0 },
    [entriesQuery.data],
  );

  /**
   * The cards: what the event has taken, from the server.
   *
   * These used to be counted here from the rows that happened to be loaded,
   * beside a total that came from the paginator — two numbers printed in one
   * sentence and measured against different populations. It merely looked right
   * while the whole list loaded at once; paginated, a fully paid event reads
   * "120 pendaftar · 25 lunas".
   */
  const summary = entriesQuery.data?.summary;
  // Every category the event runs, including ones nobody entered — from the
  // server, not from the rows on screen, because filtering to an empty one is
  // how an organizer finds out it has sold nothing.
  const categories = entriesQuery.data?.categories ?? [];

  return (
    <div className="space-y-6">
      <PageHeader
        title="Peserta Lomba"
        description="Kategori, periode harga, dan daftar pendaftar."
        backHref={`/organizer/events/${eventId}/edit`}
        actions={
          <Button asChild variant="outline">
            <Link href={`/organizer/events/${eventId}/race-scan`}>
              <ScanLine className="h-4 w-4" />
              Check-in Peserta
            </Link>
          </Button>
        }
      />

      <RaceRouteGuard event={eventQuery.data} eventId={eventId} requires="race">
        {/* Four cards, not the one-line sentence this page used to carry. The
            sentence had to be parsed to be read, and it could not hold revenue
            or check-ins at all without becoming a paragraph.

            Revenue is the organizer's net (`price`), the column the wallet
            credit reads — a card quoting the gross would read higher than the
            wallet it feeds. Check-ins are counted against the PAID entries
            because an unpaid one has no code to scan, so "N dari M" is only a
            ratio against the runners who can actually turn up. */}
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <SummaryCard
            label="Pendaftar"
            value={String(summary?.total ?? 0)}
            hint={
              (summary?.awaiting_verification ?? 0) > 0
                ? `${summary?.awaiting_verification} menunggu verifikasi`
                : undefined
            }
          />
          <SummaryCard label="Lunas" value={String(summary?.paid ?? 0)} />
          <SummaryCard
            label="Check-in"
            value={String(summary?.checked_in ?? 0)}
            hint={`dari ${summary?.paid ?? 0} peserta lunas`}
          />
          <SummaryCard label="Pendapatan" value={rupiah(summary?.revenue ?? 0)} />
        </div>

        <Card className="space-y-4 p-4">
          {/* Search and category on one baseline, the status pills on their
              own row below. The pills and the search box are both elastic, so
              sharing a line made them eat each other's width. */}
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-12">
            <Field label="Cari pendaftar" className="lg:col-span-5">
              <div className="relative">
                <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  placeholder="Nama, email, atau telepon…"
                  className="pl-9"
                />
              </div>
            </Field>

            <Field label="Kategori" className="lg:col-span-4">
              <Select
                value={category}
                onChange={(e) => pick({ category: e.target.value || undefined })}
              >
                <option value="">Semua kategori</option>
                {categories.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.name}
                  </option>
                ))}
              </Select>
            </Field>

            {/* Bottom-aligned, so it sits on the inputs' baseline rather than
                on their labels' — the export is an action on the filtered set,
                not a third field. */}
            <div className="flex items-end lg:col-span-3">
              <ExportButtons
                orgId={orgId ?? undefined}
                eventId={eventId}
                kind="race-entries"
                enabled={isExportEnabled(eventQuery.data)}
              />
            </div>
          </div>

          {/* Pills rather than a select, the shape the buyer list uses: these
              are the one control an organizer flicks between all day, and a
              dropdown hides the other six behind a click. */}
          <div className="flex flex-wrap items-center gap-1.5">
            {FILTERS.map(([key, label]) => (
              <button
                key={key}
                type="button"
                onClick={() => pick({ status: key === "all" ? undefined : key })}
                className={cn(
                  "rounded-full border px-3 py-1.5 text-xs font-semibold transition-colors",
                  filter === key
                    ? "border-transparent bg-foreground text-background"
                    : "border-border text-muted-foreground hover:text-foreground",
                )}
              >
                {label}
              </button>
            ))}
            {filtered && (
              <Button
                variant="ghost"
                size="sm"
                className="ml-auto"
                onClick={() => {
                  setSearch("");
                  pick({ status: undefined, category: undefined, q: undefined });
                }}
              >
                Reset filter
              </Button>
            )}
          </div>

          {entriesQuery.isLoading ? (
            <Skeleton className="h-32 w-full" />
          ) : (entriesQuery.data?.items.length ?? 0) === 0 ? (
            /* Two empty states, not one: with the filtering server-side an
               empty response no longer tells "nobody has entered" apart from
               "nobody matches" — only an empty response with nothing filtered
               does. */
            <EmptyState
              icon={Users}
              title={filtered ? "Tidak ada yang cocok" : "Belum ada pendaftar"}
              description={
                filtered
                  ? "Coba ubah kata kunci, kategori, atau filter status."
                  : "Pendaftar akan muncul di sini begitu mereka mengisi form publik."
              }
            />
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  {/* The eyebrow the cards above use, so the two blocks read as
                      one screen rather than two pasted together. */}
                  <tr className="border-b border-border text-left text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                    <th className="py-2 pr-3">Nama</th>
                    <th className="py-2 pr-3">Kategori</th>
                    <th className="py-2 pr-3">Periode</th>
                    {/* Right-aligned with its figures: a column of amounts is
                        there to be compared down, which left-aligned digits of
                        different lengths cannot be. */}
                    <th className="py-2 pr-3 text-right">Biaya</th>
                    <th className="py-2 pr-3">Status</th>
                    {/* The header stays blank: "Detail" is already the word on
                        every button in the column. */}
                    <th className="py-2" />
                  </tr>
                </thead>
                <tbody>
                  {entriesQuery.data?.items.map((e) => (
                    <Fragment key={e.id}>
                      <tr className="border-b border-border align-top">
                        {/* Name and contact in one cell, not two columns. They
                            are one fact in two parts — who this is and how to
                            reach them — and as a sixth column the email forced
                            the table into a horizontal scroll on a laptop,
                            pushing the Detail button off screen. */}
                        <td className="py-2.5 pr-3">
                          <p className="font-medium">{e.full_name}</p>
                          <p className="truncate text-xs text-muted-foreground">
                            {e.email}
                            {e.phone && (
                              <>
                                {" · "}
                                <span className="font-mono tabular-nums">
                                  {e.phone}
                                </span>
                              </>
                            )}
                          </p>
                        </td>
                        <td className="py-2.5 pr-3">{e.category?.name ?? "—"}</td>
                        {/* The snapshot, which is what was charged — never the
                            period row, which may since have been repriced. */}
                        <td className="py-2.5 pr-3 text-muted-foreground">
                          {e.period_name}
                        </td>
                        <td className="py-2.5 pr-3 text-right font-mono tabular-nums">
                          {rupiah(e.price)}
                        </td>
                        {/* Through the shared badge rather than a local ternary:
                            the old one printed the raw column value, so a swept
                            entry read "expired" in the middle of an Indonesian
                            table — and the pills above it now name the same
                            seven states, which is exactly the copy that must
                            not exist twice. */}
                        <td className="py-2.5 pr-3">
                          <RaceEntryStatusBadge
                            status={e.status}
                            awaitingVerification={e.awaiting_verification}
                          />
                        </td>
                        <td className="py-2.5 text-right">
                          <DetailToggle
                            open={openRows.has(e.id)}
                            onToggle={() => toggleRow(e.id)}
                          />
                        </td>
                      </tr>
                      {/* Its own row spanning the table, not a panel outside
                          it: the detail belongs under the entrant it describes,
                          and the table is what keeps it there while the list
                          scrolls. The row itself is conditional, not the panel
                          inside it — an always-rendered <tr> would leave a
                          bordered seam under every closed row. */}
                      {openRows.has(e.id) && (
                        <tr className="border-b border-border">
                          <td colSpan={6} className="pb-4 pt-1">
                            <RaceEntryDetail entry={e} schema={schema} />
                          </td>
                        </tr>
                      )}
                    </Fragment>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          {/* Shown only when there is more than one page: a pager under a list
              that fits on screen is a control with nothing to do. */}
          {meta.last_page > 1 && (
            <div className="flex flex-wrap items-center justify-between gap-3 text-sm">
              {/* `count`, not `summary.total`: the pager describes the list it
                  pages through, which is the filtered one. The total belongs to
                  the cards above, where it is the event's standing tally. */}
              <span className="text-muted-foreground">
                Halaman {meta.page} dari {meta.last_page} · {meta.count}{" "}
                pendaftar
              </span>
              <div className="flex gap-2">
                <Button
                  size="sm"
                  variant="outline"
                  onClick={() => setPage(Math.max(1, page - 1))}
                  disabled={meta.page <= 1}
                >
                  Sebelumnya
                </Button>
                <Button
                  size="sm"
                  variant="outline"
                  onClick={() => setPage(page + 1)}
                  disabled={meta.page >= meta.last_page}
                >
                  Berikutnya
                </Button>
              </div>
            </div>
          )}
        </Card>
      </RaceRouteGuard>
    </div>
  );
}

/**
 * One figure above the list.
 *
 * `hint` is the second line some of these need and others do not — a check-in
 * count means nothing without the population it is out of, while a revenue
 * figure explains itself. It is a caption, so it never competes with the
 * figure for weight.
 */
function SummaryCard({
  label,
  value,
  hint,
}: {
  label: string;
  value: string;
  hint?: string;
}) {
  return (
    <Card className="p-4">
      <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
        {label}
      </p>
      <p
        className="mt-1 text-xl font-bold"
        style={{ fontFamily: "var(--font-display)" }}
      >
        {value}
      </p>
      {hint && <p className="mt-0.5 text-xs text-muted-foreground">{hint}</p>}
    </Card>
  );
}

/**
 * One labelled control in the filter row.
 *
 * Every field carries a label, including the one whose placeholder already says
 * what it is: a labelled input and a bare select sitting in the same grid start
 * at different heights, so the row can never settle on one baseline. The same
 * shape the buyer list's filter row uses.
 */
function Field({
  label,
  className,
  children,
}: {
  label: string;
  className?: string;
  children: ReactNode;
}) {
  return (
    <label className={cn("grid gap-1.5", className)}>
      <span className="text-xs font-medium text-muted-foreground">{label}</span>
      {children}
    </label>
  );
}
