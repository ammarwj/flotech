"use client";

import { useState } from "react";
import { CalendarClock, Check, MapPin } from "lucide-react";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";

import { updateMatchSchedule } from "@/lib/api/matches";
import { parseApiError } from "@/lib/api/errors";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { fromEventInput, toEventInput, tzLabel } from "@/lib/match-dates";
import { useEventTimezone } from "./event-timezone";
import type { Match } from "@/types/api";

/** Inline editor for a fixture's kickoff time and venue (result untouched). */
export function MatchScheduleEditor({
  orgId,
  eventId,
  match,
}: {
  orgId: string;
  eventId: string;
  match: Match;
}) {
  const qc = useQueryClient();
  const tz = useEventTimezone();
  const [when, setWhen] = useState(() => toEventInput(match.scheduled_at, tz));
  const [venue, setVenue] = useState(match.venue ?? "");

  const save = useMutation({
    mutationFn: () =>
      updateMatchSchedule(orgId, match.id, {
        // What the organizer typed is the venue's wall clock, not their own —
        // an organizer in Jakarta scheduling a Jayapura match means 15:00 WIT.
        scheduled_at: fromEventInput(when, tz),
        venue: venue.trim() || null,
      }),
    onSuccess: () => {
      toast.success("Jadwal diperbarui");
      qc.invalidateQueries({ queryKey: ["matches", orgId, eventId] });
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal menyimpan jadwal.").message),
  });

  const dirty = when !== toEventInput(match.scheduled_at, tz) || venue !== (match.venue ?? "");

  return (
    <div className="flex flex-wrap items-center gap-2">
      <div className="relative">
        <CalendarClock className="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
        <Input
          type="datetime-local"
          value={when}
          onChange={(e) => setWhen(e.target.value)}
          className="h-9 w-[14.5rem] pl-8"
          aria-label={`Tanggal & jam pertandingan (${tzLabel(tz)})`}
        />
        <span className="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 text-[11px] font-semibold text-muted-foreground">
          {tzLabel(tz)}
        </span>
      </div>
      <div className="relative min-w-[10rem] flex-1">
        <MapPin className="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
        <Input
          value={venue}
          onChange={(e) => setVenue(e.target.value)}
          placeholder="Lokasi / lapangan (opsional)"
          className="h-9 pl-8"
          aria-label="Lokasi pertandingan"
        />
      </div>
      {dirty && (
        <Button size="sm" variant="outline" onClick={() => save.mutate()} disabled={save.isPending}>
          <Check className="h-4 w-4" />
          {save.isPending ? "…" : "Simpan jadwal"}
        </Button>
      )}
    </div>
  );
}
