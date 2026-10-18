"use client";

import Link from "next/link";
import { CalendarDays, MapPin, Ticket, Trophy, Users } from "lucide-react";

import { Card } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { EventStatusBadge } from "@/components/shared/status-badge";
import { useCatalog } from "@/lib/hooks/use-catalog";
import { isRaceEvent } from "@/lib/race";
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
  const { sportLabel, sportColor } = useCatalog();

  const color = sportColor(event.sport_type);
  const when = dateRange(event.start_date, event.end_date);
  const teams = `${event.approved_teams_count} tim`;
  const categoriesCount = event.categories_count ?? 1;

  /**
   * Three of the numbers below are competition shapes, and all three read as
   * facts about a fun run while being about tables it does not use:
   * `approved_teams_count` counts `teams`, the category count counts
   * `event_categories`, and the fee range is the min/max of their
   * `registration_fee`. A race keeps its entrants in `race_entries`, its
   * categories in `race_categories` and its prices in `race_periods` — so a
   * sold-out race advertises "0 tim" and "Gratis" at the same time.
   *
   * Dropped rather than swapped for the race equivalents: the catalog payload
   * carries no race aggregate, and a per-period price has no single number to
   * print. The card still says what it reliably knows — sport, dates, place —
   * and the event page has the rest.
   */
  const isRace = isRaceEvent(event);

  // Fee spans the event's categories: "Gratis", one price, or a min–max range.
  const feeMin = event.registration_fee_min ?? 0;
  const feeMax = event.registration_fee_max ?? 0;
  const feeLabel =
    feeMax <= 0
      ? "Gratis"
      : feeMin === feeMax
        ? rupiah(feeMin)
        : `${feeMin > 0 ? rupiah(feeMin) : "Gratis"} – ${rupiah(feeMax)}`;

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
              legible on top of a banner photo. inline-flex so the backing hugs
              the pill exactly instead of filling the taller inline line-box. */}
          <span className="absolute left-3 top-3 inline-flex rounded-full bg-[var(--bg)]">
            <EventStatusBadge status={event.status} />
          </span>
          {event.tickets_on_sale && (
            <span className="absolute right-3 top-3 inline-flex rounded-full bg-[var(--bg)]">
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
            {!isRace && (
              <>
                <span className="text-muted-foreground">·</span>
                <span className="text-muted-foreground">
                  {categoriesCount} kategori
                </span>
              </>
            )}
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
            {!isRace && (
              <span className="inline-flex items-center gap-1.5">
                <Users className="h-3.5 w-3.5 shrink-0" />
                {teams}
              </span>
            )}
          </div>

          {/* The footer survives for its right half — "Pendaftaran dibuka" is
              `registrationIsPubliclyOpen()`, which is as true of a race as of
              anything else. Only the price goes. */}
          <div className="mt-auto flex items-center justify-between border-t border-border pt-3 text-sm">
            <span className="font-semibold">{isRace ? "" : feeLabel}</span>
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
