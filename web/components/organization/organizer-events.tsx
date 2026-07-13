"use client";

import { useState } from "react";
import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { ChevronLeft, ChevronRight, Trophy } from "lucide-react";

import { getPublicEvents } from "@/lib/api/events";
import { PublicEventCard } from "@/components/event/public-event-card";
import { EmptyState } from "@/components/shared/empty-state";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";

/** The organizer's published events. Paged locally — the page itself is server-rendered. */
export function OrganizerEvents({ orgSlug }: { orgSlug: string }) {
  const [page, setPage] = useState(1);

  const eventsQuery = useQuery({
    queryKey: ["public-events", { org: orgSlug, page }],
    queryFn: () => getPublicEvents({ org: orgSlug, page }),
    placeholderData: keepPreviousData,
  });

  const events = eventsQuery.data?.items;
  const meta = eventsQuery.data?.meta;

  if (eventsQuery.isLoading) {
    return (
      <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
        {[0, 1, 2].map((i) => (
          <Skeleton key={i} className="h-[380px] w-full rounded-xl" />
        ))}
      </div>
    );
  }

  if (events?.length === 0) {
    return (
      <EmptyState
        icon={Trophy}
        title="Belum ada event"
        description="Penyelenggara ini belum mempublikasikan event apa pun."
      />
    );
  }

  return (
    <>
      <div
        className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3"
        style={{ opacity: eventsQuery.isPlaceholderData ? 0.6 : 1 }}
      >
        {events?.map((ev) => (
          <PublicEventCard key={ev.id} event={ev} />
        ))}
      </div>

      {meta && meta.last_page > 1 && (
        <div className="mt-10 flex items-center justify-center gap-4">
          <Button
            variant="outline"
            size="sm"
            disabled={page <= 1}
            onClick={() => setPage((p) => p - 1)}
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
            onClick={() => setPage((p) => p + 1)}
          >
            Berikutnya
            <ChevronRight className="h-4 w-4" />
          </Button>
        </div>
      )}
    </>
  );
}
