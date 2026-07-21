"use client";

import Link from "next/link";
import { useQuery } from "@tanstack/react-query";
import { Plus, Trophy, Users, Pencil, ClipboardList, ArrowUpRight, Eye, CalendarClock, Ticket, BadgeCheck, BarChart3 } from "lucide-react";

import { getEvents } from "@/lib/api/events";
import { getActiveEventLimit, countActiveEvents } from "@/lib/plan";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { EmptyState } from "@/components/shared/empty-state";
import { EventStatusBadge } from "@/components/shared/status-badge";
import { useCatalog } from "@/lib/hooks/use-catalog";

export default function EventsPage() {
  const { sportLabel, sportColor } = useCatalog();
  const { org, orgId, isLoading: orgLoading } = useActiveOrg();

  const eventsQuery = useQuery({
    queryKey: ["events", orgId],
    queryFn: () => getEvents(orgId!),
    enabled: !!orgId,
  });

  if (orgLoading) {
    return (
      <div>
        <PageHeader title="Event" description="Kelola semua turnamen organisasimu." />
        <div className="grid gap-3">
          {[0, 1, 2].map((i) => (
            <Skeleton key={i} className="h-[88px] w-full rounded-xl" />
          ))}
        </div>
      </div>
    );
  }

  const events = eventsQuery.data;
  const limit = getActiveEventLimit(org);
  const activeCount = countActiveEvents(events);
  const limitReached = limit !== null && activeCount >= limit;

  return (
    <div>
      <PageHeader
        title="Event"
        description={
          org && !org.plan
            ? "Kelola semua turnamen organisasimu. Pilih paket dulu untuk mulai membuat event."
            : limit !== null
              ? `Kelola semua turnamen organisasimu. ${activeCount}/${limit} slot event aktif terpakai.`
              : "Kelola semua turnamen organisasimu."
        }
        actions={
          limitReached ? (
            <Button asChild variant="outline">
              <Link href="/organizer/upgrade">
                <ArrowUpRight className="h-4 w-4" />
                {org && !org.plan ? "Pilih paket" : "Upgrade paket"}
              </Link>
            </Button>
          ) : (
            <Button asChild>
              <Link href="/organizer/events/new">
                <Plus className="h-4 w-4" />
                Buat Event
              </Link>
            </Button>
          )
        }
      />

      {eventsQuery.isLoading && (
        <div className="grid gap-3">
          {[0, 1, 2].map((i) => (
            <Skeleton key={i} className="h-[88px] w-full rounded-xl" />
          ))}
        </div>
      )}

      {events?.length === 0 && (
        <EmptyState
          icon={Trophy}
          title="Belum ada event"
          description="Buat event pertamamu dan mulai terima pendaftaran tim."
          action={
            <Button asChild>
              <Link href="/organizer/events/new">
                <Plus className="h-4 w-4" />
                Buat Event
              </Link>
            </Button>
          }
        />
      )}

      {/* grid-cols-1, not a bare `grid`: an implicit `auto` track sizes to
          max-content and is allowed to exceed its container, so the card — whose
          max-content is the icon, name, badge and meta row all on one nowrap
          line — was drawn wider than the phone. `grid-cols-1` is
          `minmax(0, 1fr)`, which caps the track at the container. The overflow
          never showed as a scrollbar because the dashboard's `main` clips it,
          and clipping happens at the padding box: the card simply ran to the
          right edge of the screen while the left kept its px-5. */}
      <div className="grid grid-cols-1 gap-3">
        {events?.map((ev) => {
          const color = sportColor(ev.sport_type);
          return (
            <Card
              key={ev.id}
              className="flex flex-wrap items-center justify-between gap-4 p-4 transition-colors hover:border-[var(--border-strong)]"
            >
              <div className="flex min-w-0 items-center gap-4">
                <span
                  className="grid h-12 w-12 shrink-0 place-items-center rounded-xl"
                  style={{ background: `color-mix(in srgb, ${color} 14%, transparent)`, color }}
                >
                  <Trophy className="h-5 w-5" />
                </span>
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2">
                    {/* min-w-0: `truncate` alone cannot shrink a flex item —
                        its automatic minimum size is the full nowrap text, so a
                        long event name pushes past the card instead of
                        ellipsing. */}
                    <span className="min-w-0 truncate font-semibold" style={{ fontFamily: "var(--font-display)" }}>
                      {ev.name}
                    </span>
                    <EventStatusBadge status={ev.status} />
                  </div>
                  <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground">
                    <span className="inline-flex items-center gap-1.5" style={{ color }}>
                      {sportLabel(ev.sport_type)}
                    </span>
                    <span>{ev.categories.length} kategori</span>
                    <span className="inline-flex items-center gap-1">
                      <Users className="h-3.5 w-3.5" />
                      {ev.teams_count ?? 0} tim
                    </span>
                  </div>
                </div>
              </div>
              {/* Six nowrap buttons are ~620px; the card has ~288px on a phone.
                  Full width first so they wrap inside the card instead of
                  stretching it past the viewport. */}
              <div className="flex w-full flex-wrap gap-2 sm:w-auto">
                {ev.status !== "draft" && org?.slug && (
                  <Button asChild size="sm" variant="outline">
                    <a href={`/${org.slug}/${ev.slug}`} target="_blank" rel="noopener noreferrer">
                      <Eye className="h-4 w-4" />
                      Lihat
                    </a>
                  </Button>
                )}
                <Button asChild size="sm" variant="outline">
                  <Link href={`/organizer/events/${ev.id}/registrations`}>
                    <ClipboardList className="h-4 w-4" />
                    Pendaftaran
                  </Link>
                </Button>
                <Button asChild size="sm" variant="outline">
                  <Link href={`/organizer/events/${ev.id}/schedule`}>
                    <CalendarClock className="h-4 w-4" />
                    Jadwal
                  </Link>
                </Button>
                <Button asChild size="sm" variant="outline">
                  <Link href={`/organizer/events/${ev.id}/tickets`}>
                    <Ticket className="h-4 w-4" />
                    Tiket
                  </Link>
                </Button>
                <Button asChild size="sm" variant="outline">
                  <Link href={`/organizer/events/${ev.id}/stats`}>
                    <BarChart3 className="h-4 w-4" />
                    Statistik
                  </Link>
                </Button>
                {/* Always shown, not just while the gateway is off: manual
                    orders that already have proof never expire on their own, so
                    hiding this the moment the gateway returns would strand
                    them. */}
                <Button asChild size="sm" variant="outline">
                  <Link href={`/organizer/events/${ev.id}/payments`}>
                    <BadgeCheck className="h-4 w-4" />
                    Pembayaran
                  </Link>
                </Button>
                <Button asChild size="sm" variant="outline">
                  <Link href={`/organizer/events/${ev.id}/edit`}>
                    <Pencil className="h-4 w-4" />
                    Edit
                  </Link>
                </Button>
              </div>
            </Card>
          );
        })}
      </div>
    </div>
  );
}
