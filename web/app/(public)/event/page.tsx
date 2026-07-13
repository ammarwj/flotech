"use client";

import { Suspense, useCallback, useEffect, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { ChevronLeft, ChevronRight, Search, Trophy } from "lucide-react";

import { getPublicEvents } from "@/lib/api/events";
import { useCatalog } from "@/lib/hooks/use-catalog";
import { EVENT_STATUS_LABELS } from "@/lib/labels";
import type { EventStatus } from "@/types/api";
import { Nav } from "@/components/landing/nav";
import { Footer } from "@/components/landing/footer";
import { PublicEventCard } from "@/components/event/public-event-card";
import { EmptyState } from "@/components/shared/empty-state";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";

// Draft events are never public, so they can't be browsed to.
const STATUS_FILTERS = (Object.keys(EVENT_STATUS_LABELS) as EventStatus[]).filter(
  (s) => s !== "draft"
);

function EventCatalog() {
  const router = useRouter();
  const params = useSearchParams();
  const { sports } = useCatalog();

  const search = params.get("search") ?? "";
  const sport = params.get("sport") ?? "";
  const status = params.get("status") ?? "";
  const page = Math.max(1, Number(params.get("page")) || 1);

  // The search box is typed into freely; the URL (and the query) only catch up
  // once typing pauses.
  const [term, setTerm] = useState(search);

  const setParams = useCallback(
    (patch: Record<string, string | number | undefined>) => {
      const next = new URLSearchParams(params.toString());

      for (const [key, value] of Object.entries(patch)) {
        if (value === undefined || value === "") next.delete(key);
        else next.set(key, String(value));
      }

      const qs = next.toString();
      router.replace(qs ? `/event?${qs}` : "/event", { scroll: false });
    },
    [params, router]
  );

  useEffect(() => {
    if (term === search) return;
    const timer = setTimeout(() => setParams({ search: term, page: undefined }), 300);
    return () => clearTimeout(timer);
  }, [term, search, setParams]);

  const eventsQuery = useQuery({
    queryKey: ["public-events", { search, sport, status, page }],
    queryFn: () =>
      getPublicEvents({
        search: search || undefined,
        sport: sport || undefined,
        status: (status as EventStatus) || undefined,
        page,
      }),
    // Keep the old grid on screen while the next page loads, so the layout
    // doesn't collapse to skeletons on every keystroke.
    placeholderData: keepPreviousData,
  });

  const events = eventsQuery.data?.items;
  const meta = eventsQuery.data?.meta;
  const isFiltered = Boolean(search || sport || status);

  return (
    <>
      <Nav />

      <main>
        <section className="section-sm">
          <div className="container">
            <div className="section-head">
              <p className="eyebrow">Katalog</p>
              <h1 className="section-title">Jelajahi Event</h1>
              <p className="section-sub">
                Turnamen yang sedang berjalan dan membuka pendaftaran di flo-event.
              </p>
            </div>

            <div className="mt-8 flex flex-col gap-3 sm:flex-row">
              <div className="relative flex-1">
                <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                  value={term}
                  onChange={(e) => setTerm(e.target.value)}
                  placeholder="Cari event, lokasi, atau penyelenggara…"
                  className="pl-9"
                  aria-label="Cari event"
                />
              </div>

              <Select
                value={sport}
                onChange={(e) => setParams({ sport: e.target.value, page: undefined })}
                className="sm:w-52"
                aria-label="Cabang olahraga"
              >
                <option value="">Semua cabang</option>
                {sports.map((s) => (
                  <option key={s.slug} value={s.slug}>
                    {s.name}
                  </option>
                ))}
              </Select>

              <Select
                value={status}
                onChange={(e) => setParams({ status: e.target.value, page: undefined })}
                className="sm:w-52"
                aria-label="Status event"
              >
                <option value="">Semua status</option>
                {STATUS_FILTERS.map((s) => (
                  <option key={s} value={s}>
                    {EVENT_STATUS_LABELS[s]}
                  </option>
                ))}
              </Select>
            </div>

            <div className="mt-8">
              {eventsQuery.isLoading ? (
                <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                  {[0, 1, 2, 3, 4, 5].map((i) => (
                    <Skeleton key={i} className="h-[380px] w-full rounded-xl" />
                  ))}
                </div>
              ) : events?.length === 0 ? (
                <EmptyState
                  icon={Trophy}
                  title={isFiltered ? "Tidak ada event yang cocok" : "Belum ada event"}
                  description={
                    isFiltered
                      ? "Coba ubah kata kunci atau filter pencarianmu."
                      : "Belum ada turnamen yang dipublikasikan. Cek lagi nanti."
                  }
                  action={
                    isFiltered ? (
                      <Button
                        variant="outline"
                        onClick={() => {
                          setTerm("");
                          router.replace("/event", { scroll: false });
                        }}
                      >
                        Hapus filter
                      </Button>
                    ) : undefined
                  }
                />
              ) : (
                <div
                  className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3"
                  style={{ opacity: eventsQuery.isPlaceholderData ? 0.6 : 1 }}
                >
                  {events?.map((ev) => (
                    <PublicEventCard key={ev.id} event={ev} />
                  ))}
                </div>
              )}
            </div>

            {meta && meta.last_page > 1 && (
              <div className="mt-10 flex items-center justify-center gap-4">
                <Button
                  variant="outline"
                  size="sm"
                  disabled={page <= 1}
                  onClick={() => setParams({ page: page - 1 })}
                >
                  <ChevronLeft className="h-4 w-4" />
                  Sebelumnya
                </Button>
                <span className="text-sm text-muted-foreground">
                  Halaman {meta.page} dari {meta.last_page}
                </span>
                <Button
                  variant="outline"
                  size="sm"
                  disabled={page >= meta.last_page}
                  onClick={() => setParams({ page: page + 1 })}
                >
                  Berikutnya
                  <ChevronRight className="h-4 w-4" />
                </Button>
              </div>
            )}
          </div>
        </section>
      </main>

      <Footer />
    </>
  );
}

export default function EventCatalogPage() {
  // useSearchParams() needs a Suspense boundary or the build fails.
  return (
    <Suspense fallback={null}>
      <EventCatalog />
    </Suspense>
  );
}
