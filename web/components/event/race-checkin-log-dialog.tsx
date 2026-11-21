"use client";

import { useEffect, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Search, Users } from "lucide-react";

import { getRaceCheckins } from "@/lib/api/race";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogBody,
  DialogContent,
  DialogFooter,
  DialogHeader,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";

const PER_PAGE = 25;

/**
 * The whole check-in log, not the desk card's tail.
 *
 * The card on the scan page answers "who just came through" — ten names, no
 * controls, because that is what staff glance at between runners. This answers
 * the other question the desk asks: "did so-and-so already check in?", which a
 * tail of ten cannot, and which is why the search box is the point of this
 * dialog rather than an extra on it.
 *
 * Searching and paging are the server's (`RaceScanController::checkins` takes
 * `q`/`page`/`per_page` and matches name + bib through `Search::anyColumn`).
 * Filtering a page client-side would search the twenty-five rows that happen to
 * be loaded and answer "tidak ada" about a runner who checked in an hour ago.
 */
export function RaceCheckinLogDialog({
  orgId,
  eventId,
  open,
  onClose,
}: {
  orgId: string;
  eventId: string;
  open: boolean;
  onClose: () => void;
}) {
  const [search, setSearch] = useState("");
  const [q, setQ] = useState("");
  const [page, setPage] = useState(1);

  // Debounced so a request does not go out per keystroke, and `page` resets
  // with every new term — page 3 of the old term is a page the new one may not
  // have, which Laravel answers with an empty list rather than an error.
  useEffect(() => {
    if (search === q) return;
    const t = setTimeout(() => {
      setQ(search);
      setPage(1);
    }, 350);
    return () => clearTimeout(t);
  }, [search, q]);

  const logQuery = useQuery({
    queryKey: ["race-checkins", orgId, eventId, { q, page, per_page: PER_PAGE }],
    queryFn: () =>
      getRaceCheckins(orgId, eventId, { q: q || undefined, page, per_page: PER_PAGE }),
    enabled: open,
    // The current page stays on screen while the next loads — without it every
    // page turn blanks back to a skeleton and the list jumps.
    placeholderData: (prev) => prev,
  });

  const items = logQuery.data?.items ?? [];
  const meta = logQuery.data?.meta ?? { page: 1, last_page: 1, total: 0 };

  return (
    <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
      <DialogContent className="max-w-2xl">
        <DialogHeader
          icon={Users}
          title="Semua check-in"
          description="Cari nama atau nomor BIB untuk memastikan seseorang sudah datang."
        />
        <DialogBody>
          <div className="relative">
            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Nama atau nomor BIB…"
              className="pl-9"
            />
          </div>

          {logQuery.isLoading ? (
            <Skeleton className="h-64 w-full" />
          ) : items.length === 0 ? (
            // Two readings, and the desk acts differently on each: an empty
            // search means "orang ini belum datang", an empty log means the
            // race has not started.
            <p className="py-8 text-center text-sm text-muted-foreground">
              {q
                ? "Tidak ada peserta yang cocok dan sudah check-in."
                : "Belum ada yang datang."}
            </p>
          ) : (
            <ul className="divide-y divide-border text-sm">
              {items.map((e) => (
                <li key={e.id} className="flex items-baseline justify-between gap-3 py-2.5">
                  <span className="min-w-0">
                    <span className="block truncate font-medium">{e.full_name}</span>
                    <span className="text-xs text-muted-foreground">
                      {e.category ?? "—"}
                      {e.bib_number ? ` · BIB ${e.bib_number}` : ""}
                    </span>
                  </span>
                  <span className="shrink-0 text-xs text-muted-foreground">
                    {/* Date and time here, unlike the desk card: that one shows
                        a tail from the last few minutes, this one can reach
                        back to the pack collection the evening before. */}
                    {e.checked_in_at
                      ? new Date(e.checked_in_at).toLocaleString("id-ID", {
                          day: "2-digit",
                          month: "short",
                          hour: "2-digit",
                          minute: "2-digit",
                        })
                      : "—"}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </DialogBody>
        <DialogFooter className="sm:justify-between">
          <span className="text-xs text-muted-foreground">
            {meta.total} peserta sudah check-in
            {meta.last_page > 1 ? ` · halaman ${meta.page} dari ${meta.last_page}` : ""}
          </span>
          {/* Shown only when there is more than one page: a pager under a list
              that fits on screen is a control with nothing to do. */}
          {meta.last_page > 1 && (
            <div className="flex gap-2">
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
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
