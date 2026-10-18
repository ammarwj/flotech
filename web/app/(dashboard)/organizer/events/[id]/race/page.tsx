"use client";

import { Fragment, useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { useQuery } from "@tanstack/react-query";
import { ScanLine, Users } from "lucide-react";

import { getRaceEntries } from "@/lib/api/race";
import { getEvent } from "@/lib/api/events";
import { isExportEnabled } from "@/lib/plan";
import { rupiah } from "@/lib/labels";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
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
 * Everything a fun run organizer sets up and watches: the sale windows, the
 * categories priced against them, and the runners who entered.
 *
 * One page rather than three ROUTES, because the three are unreadable apart: a
 * price means nothing without the window it belongs to, and a window means
 * nothing until some category charges for it. The ticket side can afford to
 * split categories from buyers — a ticket price is one number.
 *
 * Tabbed rather than stacked, though. Setup is read once and the entrant list
 * is read every day, and stacked the list sat below two cards that stop
 * changing after the first week — so the thing an organizer came for was always
 * the thing they had to scroll past the others to reach. The tab lives in the
 * URL for the same reason the buyers page keeps its filters there: refreshing
 * or coming back from a runner's detail must land where you were.
 */
export default function EventRacePage() {
  const params = useParams<{ id: string }>();
  const eventId = params.id;
  const { orgId } = useActiveOrg();

  const { params: sp, setParams } = useUrlState();

  const page = Math.max(1, Number(sp.get("page")) || 1);
  const q = sp.get("q") ?? "";

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
    queryKey: ["race-entries", orgId, eventId, { q, page }],
    queryFn: () =>
      getRaceEntries(orgId!, eventId, {
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
   * The paging figures and the headline counts, all from the server.
   *
   * `paid` and `awaiting` used to be counted here from the rows that happened
   * to be loaded, beside a `total` that came from the paginator — two numbers
   * printed in one sentence and measured against different populations. It
   * merely looked right while the whole list loaded at once; paginated, a fully
   * paid event reads "120 pendaftar · 25 lunas". Counted server-side now, off
   * the same filtered query the page comes out of.
   */
  const meta = useMemo(
    () =>
      entriesQuery.data?.meta ?? {
        page: 1,
        last_page: 1,
        total: 0,
        paid: 0,
        awaiting_verification: 0,
      },
    [entriesQuery.data],
  );

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
        <Card className="space-y-4 p-4">
          <div className="flex flex-wrap items-center justify-between gap-3">
            {/* No heading: the page is titled "Peserta Lomba" and holds one
                thing now. The counts stay — those are what the title cannot
                carry. */}
            <p className="text-sm text-muted-foreground">
              {meta.total} pendaftar · {meta.paid} lunas
              {meta.awaiting_verification > 0 &&
                ` · ${meta.awaiting_verification} menunggu verifikasi`}
            </p>
            <div className="flex flex-wrap items-center gap-2">
              <Input
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Cari nama, email, telepon"
                className="w-full sm:w-56"
              />
              <ExportButtons
                orgId={orgId ?? undefined}
                eventId={eventId}
                kind="race-entries"
                enabled={isExportEnabled(eventQuery.data)}
              />
            </div>
          </div>

          {entriesQuery.isLoading ? (
            <Skeleton className="h-32 w-full" />
          ) : (entriesQuery.data?.items.length ?? 0) === 0 ? (
            <EmptyState
              icon={Users}
              title={search ? "Tidak ada yang cocok" : "Belum ada pendaftar"}
              description={
                search
                  ? "Coba kata kunci lain."
                  : "Pendaftar akan muncul di sini begitu mereka mengisi form publik."
              }
            />
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-border text-left text-muted-foreground">
                    <th className="py-2 pr-3 font-medium">Nama</th>
                    <th className="py-2 pr-3 font-medium">Kategori</th>
                    <th className="py-2 pr-3 font-medium">Periode</th>
                    <th className="py-2 pr-3 font-medium">Biaya</th>
                    <th className="py-2 pr-3 font-medium">Status</th>
                    <th className="py-2 pr-3 font-medium">Kontak</th>
                    {/* The header stays blank: "Detail" is already the word on
                        every button in the column. */}
                    <th className="py-2 font-medium" />
                  </tr>
                </thead>
                <tbody>
                  {entriesQuery.data?.items.map((e) => (
                    <Fragment key={e.id}>
                    <tr className="border-b border-border">
                      <td className="py-2 pr-3 font-medium">{e.full_name}</td>
                      <td className="py-2 pr-3">{e.category?.name ?? "—"}</td>
                      {/* The snapshot, which is what was charged — never the
                          period row, which may since have been repriced. */}
                      <td className="py-2 pr-3">{e.period_name}</td>
                      <td className="py-2 pr-3">{rupiah(e.price)}</td>
                      <td className="py-2 pr-3">
                        <Badge
                          variant={
                            e.status === "paid"
                              ? "success"
                              : e.awaiting_verification
                                ? "warning"
                                : e.status === "pending"
                                  ? "outline"
                                  : "neutral"
                          }
                        >
                          {e.awaiting_verification ? "Menunggu verifikasi" : e.status}
                        </Badge>
                      </td>
                      <td className="py-2 pr-3 text-muted-foreground">
                        {e.email}
                        {e.phone && ` · ${e.phone}`}
                      </td>
                      <td className="py-2 text-right">
                        <DetailToggle
                          open={openRows.has(e.id)}
                          onToggle={() => toggleRow(e.id)}
                        />
                      </td>
                    </tr>
                    {/* Its own row spanning the table, not a panel outside it:
                        the detail belongs under the entrant it describes, and
                        the table is what keeps it there while the list scrolls.
                        The row itself is conditional, not the panel inside it —
                        an always-rendered <tr> would leave a bordered seam
                        under every closed row. */}
                    {openRows.has(e.id) && (
                      <tr className="border-b border-border">
                        <td colSpan={7} className="pb-4 pt-1">
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
              <span className="text-muted-foreground">
                Halaman {meta.page} dari {meta.last_page} · {meta.total}{" "}
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
