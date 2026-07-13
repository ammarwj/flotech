"use client";

import Link from "next/link";
import { useQuery } from "@tanstack/react-query";
import { Ticket, Trophy, ArrowUpRight, ScanLine, ArrowRight } from "lucide-react";

import { getEvents } from "@/lib/api/events";
import { isTicketingEnabled } from "@/lib/plan";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { EmptyState } from "@/components/shared/empty-state";
import { EventStatusBadge } from "@/components/shared/status-badge";
import { useCatalog } from "@/lib/hooks/use-catalog";

export default function TicketsOverviewPage() {
  const { sportLabel, sportColor } = useCatalog();
  const { org, orgId, isLoading: orgLoading } = useActiveOrg();
  const ticketing = isTicketingEnabled(org);

  const eventsQuery = useQuery({
    queryKey: ["events", orgId],
    queryFn: () => getEvents(orgId!),
    enabled: !!orgId,
  });

  if (orgLoading) {
    return (
      <div>
        <PageHeader title="Tiket" description="Kelola penjualan tiket tiap event." />
        <div className="grid gap-3">
          {[0, 1, 2].map((i) => (
            <Skeleton key={i} className="h-[80px] w-full rounded-xl" />
          ))}
        </div>
      </div>
    );
  }

  if (org && !ticketing) {
    return (
      <div>
        <PageHeader title="Tiket" description="Jual tiket digital dengan QR Code." />
        <EmptyState
          icon={Ticket}
          title="Fitur tiket belum aktif di paketmu"
          description="Upgrade ke paket Starter atau lebih tinggi untuk menjual tiket QR Code dan mengelola check-in penonton."
          action={
            <Button asChild>
              <Link href="/organizer/upgrade">
                <ArrowUpRight className="h-4 w-4" />
                Upgrade paket
              </Link>
            </Button>
          }
        />
      </div>
    );
  }

  const events = eventsQuery.data;

  return (
    <div>
      <PageHeader
        title="Tiket"
        description="Pilih event untuk mengelola kategori tiket, penjualan, dan check-in."
      />

      {eventsQuery.isLoading && (
        <div className="grid gap-3">
          {[0, 1, 2].map((i) => (
            <Skeleton key={i} className="h-[80px] w-full rounded-xl" />
          ))}
        </div>
      )}

      {events?.length === 0 && (
        <EmptyState
          icon={Trophy}
          title="Belum ada event"
          description="Buat event dulu, lalu siapkan kategori tiketnya."
          action={
            <Button asChild>
              <Link href="/organizer/events/new">Buat Event</Link>
            </Button>
          }
        />
      )}

      <div className="grid gap-3">
        {events?.map((ev) => {
          const color = sportColor(ev.sport_type);
          return (
            <Card key={ev.id} className="flex flex-wrap items-center justify-between gap-4 p-4">
              <div className="flex min-w-0 items-center gap-4">
                <span
                  className="grid h-11 w-11 shrink-0 place-items-center rounded-xl"
                  style={{ background: `color-mix(in srgb, ${color} 14%, transparent)`, color }}
                >
                  <Ticket className="h-5 w-5" />
                </span>
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="truncate font-semibold" style={{ fontFamily: "var(--font-display)" }}>
                      {ev.name}
                    </span>
                    <EventStatusBadge status={ev.status} />
                  </div>
                  <div className="mt-1 text-sm text-muted-foreground">{sportLabel(ev.sport_type)}</div>
                </div>
              </div>
              <div className="flex gap-2">
                <Button asChild size="sm" variant="outline">
                  <Link href={`/organizer/events/${ev.id}/scan`}>
                    <ScanLine className="h-4 w-4" />
                    Scan
                  </Link>
                </Button>
                <Button asChild size="sm">
                  <Link href={`/organizer/events/${ev.id}/tickets`}>
                    Kelola tiket
                    <ArrowRight className="h-4 w-4" />
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
