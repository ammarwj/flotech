"use client";

import Link from "next/link";
import { CalendarDays, MapPin, Ticket, Trophy, Users } from "lucide-react";

import { Card } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { EventStatusBadge } from "@/components/shared/status-badge";
import { useCatalog } from "@/lib/hooks/use-catalog";
import { rupiah } from "@/lib/labels";
import type { PublicEventListItem } from "@/types/api";

const fmtDate = (d: string | null) =>
  d ? new Date(d).toLocaleDateString("id-ID", { day: "numeric", month: "short", year: "numeric" }) : null;

function dateRange(start: string | null, end: string | null) {
  const from = fmtDate(start);
  const to = fmtDate(end);
  if (!from) return null;
  return !to || to === from ? from : `${from} – ${to}`;
}

export function PublicEventCard({ event }: { event: PublicEventListItem }) {
  const { sportLabel, sportColor, formatLabel } = useCatalog();

  const color = sportColor(event.sport_type);
  const when = dateRange(event.start_date, event.end_date);
  const teams = event.max_teams
    ? `${event.approved_teams_count}/${event.max_teams} tim`
    : `${event.approved_teams_count} tim`;

  return (
    <Card className="group overflow-hidden transition-colors hover:border-[var(--border-strong)]">
      <Link href={`/${event.organization.slug}/${event.slug}`} className="flex h-full flex-col">
        <div
          className="relative aspect-[16/9] overflow-hidden"
          style={{ background: `color-mix(in srgb, ${color} 14%, transparent)` }}
        >
          {event.banner_url ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img
              src={event.banner_url}
              alt=""
              className="h-full w-full object-cover transition-transform duration-300 group-hover:scale-[1.03]"
            />
          ) : (
            <span className="grid h-full w-full place-items-center" style={{ color }}>
              <Trophy className="h-10 w-10" />
            </span>
          )}
          {/* Badge tints are translucent, so they need a solid backing to stay
              legible on top of a banner photo. */}
          <span className="absolute left-3 top-3 rounded-full bg-[var(--bg)]">
            <EventStatusBadge status={event.status} />
          </span>
          {event.tickets_on_sale && (
            <span className="absolute right-3 top-3 rounded-full bg-[var(--bg)]">
              <Badge variant="info">
                <Ticket className="h-3 w-3" />
                Tiket
              </Badge>
            </span>
          )}
        </div>

        <div className="flex flex-1 flex-col gap-2 p-4">
          <div className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
            <span className="font-medium" style={{ color }}>
              {sportLabel(event.sport_type)}
            </span>
            <span className="text-muted-foreground">·</span>
            <span className="text-muted-foreground">{formatLabel(event.tournament_format)}</span>
          </div>

          <h3
            className="line-clamp-2 font-semibold leading-snug"
            style={{ fontFamily: "var(--font-display)" }}
          >
            {event.name}
          </h3>

          {event.organization.name && (
            <p className="truncate text-sm text-muted-foreground">{event.organization.name}</p>
          )}

          <div className="mt-1 flex flex-col gap-1.5 text-sm text-muted-foreground">
            {when && (
              <span className="inline-flex items-center gap-1.5">
                <CalendarDays className="h-3.5 w-3.5 shrink-0" />
                {when}
              </span>
            )}
            {event.location_name && (
              <span className="inline-flex items-center gap-1.5">
                <MapPin className="h-3.5 w-3.5 shrink-0" />
                <span className="truncate">{event.location_name}</span>
              </span>
            )}
            <span className="inline-flex items-center gap-1.5">
              <Users className="h-3.5 w-3.5 shrink-0" />
              {teams}
            </span>
          </div>

          <div className="mt-auto flex items-center justify-between border-t border-border pt-3 text-sm">
            <span className="font-semibold">
              {event.registration_fee > 0 ? rupiah(event.registration_fee) : "Gratis"}
            </span>
            {event.registration_is_open && (
              <span className="text-xs font-medium text-[var(--brand-600)]">
                Pendaftaran dibuka
              </span>
            )}
          </div>
        </div>
      </Link>
    </Card>
  );
}
