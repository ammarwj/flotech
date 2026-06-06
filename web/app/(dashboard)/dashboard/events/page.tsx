"use client";

import Link from "next/link";
import { useQuery } from "@tanstack/react-query";

import { getEvents } from "@/lib/api/events";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { Button } from "@/components/ui/button";
import { SPORT_LABELS, EVENT_STATUS_LABELS } from "@/lib/labels";

export default function EventsPage() {
  const { orgId, hasNoOrg, isLoading: orgLoading } = useActiveOrg();

  const eventsQuery = useQuery({
    queryKey: ["events", orgId],
    queryFn: () => getEvents(orgId!),
    enabled: !!orgId,
  });

  if (orgLoading) return <p className="text-muted-foreground">Memuat…</p>;

  if (hasNoOrg) {
    return (
      <div>
        <p className="text-muted-foreground">Kamu belum punya organisasi.</p>
        <Button asChild className="mt-4">
          <Link href="/onboarding">Buat organisasi</Link>
        </Button>
      </div>
    );
  }

  return (
    <div>
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold" style={{ fontFamily: "var(--font-display)" }}>
          Event
        </h1>
        <Button asChild>
          <Link href="/dashboard/events/new">+ Buat Event</Link>
        </Button>
      </div>

      {eventsQuery.isLoading && <p className="mt-6 text-muted-foreground">Memuat event…</p>}
      {eventsQuery.data?.length === 0 && (
        <p className="mt-6 text-muted-foreground">Belum ada event. Buat event pertamamu.</p>
      )}

      <div className="mt-6 grid gap-3">
        {eventsQuery.data?.map((ev) => (
          <div key={ev.id} className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border bg-card p-4">
            <div>
              <div className="font-semibold" style={{ fontFamily: "var(--font-display)" }}>
                {ev.name}
              </div>
              <div className="mt-1 text-sm text-muted-foreground">
                {SPORT_LABELS[ev.sport_type]} · {EVENT_STATUS_LABELS[ev.status]} · {ev.teams_count ?? 0} tim
              </div>
            </div>
            <div className="flex gap-2">
              <Button asChild size="sm" variant="outline">
                <Link href={`/dashboard/events/${ev.id}/registrations`}>Pendaftaran</Link>
              </Button>
              <Button asChild size="sm" variant="outline">
                <Link href={`/dashboard/events/${ev.id}/edit`}>Edit</Link>
              </Button>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
