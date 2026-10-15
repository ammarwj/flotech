"use client";

import Link from "next/link";
import { CalendarClock, Flag } from "lucide-react";

import { Button } from "@/components/ui/button";
import { EmptyState } from "@/components/shared/empty-state";
import { isRaceEvent, hasFixtures } from "@/lib/race";
import type { SportEvent } from "@/types/api";

/**
 * What a competition-only page renders when its event turns out to be a fun run,
 * and the other way round.
 *
 * Hiding the button on the event card is only half the job: every route still
 * answers its own URL, and an organizer arriving from an old tab or a bookmark
 * would otherwise meet a page querying fixtures that cannot exist. The
 * precedent is `/[orgSlug]/[eventSlug]/register`, where hiding the CTA left the
 * URL open and the form 422'd on submit instead of saying so up front.
 *
 * A component rather than a redirect, deliberately: a redirect would bounce
 * somebody who typed the URL on purpose without ever telling them why, and this
 * is the one screen that can explain which page they actually want.
 */
export function RaceRouteGuard({
  event,
  eventId,
  /**
   * `"competition"` — this page needs fixtures (schedule, personnel, deposits,
   * registrations). `"race"` — this page needs a race (the entries page).
   */
  requires,
  children,
}: {
  event: SportEvent | null | undefined;
  eventId: string;
  requires: "competition" | "race";
  children: React.ReactNode;
}) {
  // Nothing is said until the event is loaded. `hasFixtures()` answers true for
  // an unloaded event for exactly this reason — flashing a refusal over a page
  // that is about to render fine is worse than a moment of skeleton.
  if (!event) return <>{children}</>;

  if (requires === "competition" && isRaceEvent(event)) {
    return (
      <EmptyState
        icon={Flag}
        title="Halaman ini untuk event pertandingan"
        description="Event ini adalah lomba tanpa pertandingan — tidak ada jadwal, klasemen, atau bracket. Peserta, kategori, dan periode harganya diatur di halaman Peserta Lomba."
        action={
          <Button asChild>
            <Link href={`/organizer/events/${eventId}/race`}>Ke Peserta Lomba</Link>
          </Button>
        }
      />
    );
  }

  if (requires === "race" && hasFixtures(event)) {
    return (
      <EmptyState
        icon={CalendarClock}
        title="Event ini bukan lomba"
        description="Halaman ini hanya untuk cabang tanpa pertandingan. Peserta event ini mendaftar sebagai tim — kelola mereka di halaman Pendaftaran."
        action={
          <Button asChild>
            <Link href={`/organizer/events/${eventId}/registrations`}>Ke Pendaftaran</Link>
          </Button>
        }
      />
    );
  }

  return <>{children}</>;
}
