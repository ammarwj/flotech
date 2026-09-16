"use client";

import Link from "next/link";
import { useQuery } from "@tanstack/react-query";
import { CalendarDays, ClipboardList, MapPin, ShieldCheck, Trophy } from "lucide-react";

import { getOfficiatingEvents } from "@/lib/api/officiating";
import { Card } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { EmptyState } from "@/components/shared/empty-state";
import { EventStatusBadge } from "@/components/shared/status-badge";
import type { EventStatus } from "@/types/api";

const fmtDate = (d: string | null) =>
  d
    ? new Date(d).toLocaleDateString("id-ID", {
        day: "numeric",
        month: "short",
        year: "numeric",
      })
    : null;

function dateRange(start: string | null, end: string | null) {
  const from = fmtDate(start);
  const to = fmtDate(end);
  if (!from) return null;
  return !to || to === from ? from : `${from} – ${to}`;
}

/**
 * Where a referee or match staff lands.
 *
 * No redirect when there is exactly one assignment, even though that is the
 * common case: the crew of a weekend tournament would then have no page that
 * ever shows them what they are assigned to, and the one event they do have
 * would look like the whole application rather than one job. The card is one
 * click and it says who they are on this event.
 */
export default function OfficiatingPage() {
  const query = useQuery({
    queryKey: ["officiating-events"],
    queryFn: getOfficiatingEvents,
  });
  const events = query.data;

  return (
    <div>
      <PageHeader
        title="Area Petugas"
        description="Event yang menugaskanmu sebagai wasit atau staf pertandingan."
      />

      {query.isLoading && (
        <div className="grid gap-3">
          {[0, 1].map((i) => (
            <Skeleton key={i} className="h-[92px] w-full rounded-xl" />
          ))}
        </div>
      )}

      {events?.length === 0 && (
        <EmptyState
          icon={ClipboardList}
          title="Belum ada penugasan"
          // Says what to do about it, and who does it: there is no self-service
          // path into this area at all, so "coba lagi nanti" would be a dead end.
          description="Kamu belum ditugaskan di event mana pun. Penugasan dibuat oleh panitia event — hubungi mereka kalau seharusnya kamu ada di sini."
        />
      )}

      <div className="grid gap-3">
        {events?.map((e) => {
          const when = dateRange(e.start_date, e.end_date);
          return (
            <Card key={e.personnel_id} className="flex flex-wrap items-center gap-4 p-4">
              <span className="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-[var(--tint)] text-[var(--brand-600)]">
                <ShieldCheck className="h-5 w-5" />
              </span>
              <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                  <span
                    className="font-semibold"
                    style={{ fontFamily: "var(--font-display)" }}
                  >
                    {e.event_name}
                  </span>
                  <EventStatusBadge status={e.event_status as EventStatus} />
                  {/* The job title, not the bucket: "Wasit Utama" if the
                      organizer typed one, the kind's label otherwise. */}
                  <Badge variant="info">{e.role_display}</Badge>
                </div>
                <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground">
                  {when && (
                    <span className="inline-flex items-center gap-1.5">
                      <CalendarDays className="h-3.5 w-3.5" />
                      {when}
                    </span>
                  )}
                  {e.location_name && (
                    <span className="inline-flex items-center gap-1.5">
                      <MapPin className="h-3.5 w-3.5" />
                      {e.location_name}
                    </span>
                  )}
                </div>
              </div>
              <Button asChild size="sm" variant="outline" className="shrink-0">
                <Link href={`/officiating/events/${e.event_id}`}>
                  <Trophy className="h-4 w-4" />
                  Buka
                </Link>
              </Button>
            </Card>
          );
        })}
      </div>
    </div>
  );
}
