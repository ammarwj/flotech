"use client";

import { useState } from "react";
import { format, parseISO } from "date-fns";
import { CalendarClock, Check, MapPin } from "lucide-react";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";

import { updateMatchSchedule } from "@/lib/api/matches";
import { parseApiError } from "@/lib/api/errors";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import type { Match } from "@/types/api";

/** ISO → value for <input type="datetime-local"> (local time, no seconds). */
const toLocalInput = (iso: string | null) => (iso ? format(parseISO(iso), "yyyy-MM-dd'T'HH:mm") : "");

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
  const [when, setWhen] = useState(() => toLocalInput(match.scheduled_at));
  const [venue, setVenue] = useState(match.venue ?? "");

  const save = useMutation({
    mutationFn: () =>
      updateMatchSchedule(orgId, match.id, {
        // datetime-local is local time → send as ISO so the backend stores it correctly.
        scheduled_at: when ? new Date(when).toISOString() : null,
        venue: venue.trim() || null,
      }),
    onSuccess: () => {
      toast.success("Jadwal diperbarui");
      qc.invalidateQueries({ queryKey: ["matches", orgId, eventId] });
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal menyimpan jadwal.").message),
  });

  const dirty = when !== toLocalInput(match.scheduled_at) || venue !== (match.venue ?? "");

  return (
    <div className="flex flex-wrap items-center gap-2">
      <div className="relative">
        <CalendarClock className="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
        <Input
          type="datetime-local"
          value={when}
          onChange={(e) => setWhen(e.target.value)}
          className="h-9 w-[14.5rem] pl-8"
          aria-label="Tanggal & jam pertandingan"
        />
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
