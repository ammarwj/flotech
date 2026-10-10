"use client";

import { useState } from "react";
import { Check } from "lucide-react";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";

import { updateMatchSchedule } from "@/lib/api/matches";
import { parseApiError } from "@/lib/api/errors";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Button } from "@/components/ui/button";
import { fromEventInput, toEventInput, tzLabel } from "@/lib/match-dates";
import { useEventTimezone } from "./event-timezone";
import { CourtSelect } from "./court-select";
import type { Match } from "@/types/api";

/**
 * Inline editor for a fixture's kickoff time, venue and public note (result
 * untouched).
 *
 * The note rides along with the schedule rather than getting a save of its own:
 * all three are "when, where, and what to say about it", and one dirty-check
 * means an organizer who fixes the court and explains why saves once.
 */
export function MatchScheduleEditor({
  orgId,
  eventId,
  match,
  courts,
}: {
  orgId: string;
  eventId: string;
  match: Match;
  /** Named courts to pick from; empty falls back to a free-text field. */
  courts: string[];
}) {
  const qc = useQueryClient();
  const tz = useEventTimezone();
  const [when, setWhen] = useState(() => toEventInput(match.scheduled_at, tz));
  const [venue, setVenue] = useState(match.venue ?? "");
  const [notes, setNotes] = useState(match.notes ?? "");

  const save = useMutation({
    mutationFn: () =>
      updateMatchSchedule(orgId, match.id, {
        // What the organizer typed is the venue's wall clock, not their own —
        // an organizer in Jakarta scheduling a Jayapura match means 15:00 WIT.
        scheduled_at: fromEventInput(when, tz),
        venue: venue.trim() || null,
        // Trimmed to null like the venue beside it: whitespace stored as a note
        // reads as "filled" to every public surface, which would render an
        // empty strip under the team names.
        notes: notes.trim() || null,
      }),
    onSuccess: () => {
      toast.success("Jadwal diperbarui");
      qc.invalidateQueries({ queryKey: ["matches", orgId, eventId] });
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal menyimpan jadwal.").message),
  });

  const dirty =
    when !== toEventInput(match.scheduled_at, tz) ||
    venue !== (match.venue ?? "") ||
    notes !== (match.notes ?? "");

  return (
    // A column, because the note is a sentence: left in the same flex row as the
    // time and the court it would either squash them or wrap into a line nobody
    // can tell the start of.
    <div className="grid gap-2">
      <div className="flex flex-wrap items-center gap-2">
        {/* The zone sits beside the field, not inside it: datetime-local draws a
            native picker icon at its right edge (steppers on Safari), which no
            amount of padding reliably clears. Kept in one flex box so the label
            never wraps away from the input it describes. */}
        <div className="flex shrink-0 items-center gap-1.5">
          {/* No decorative leading icon here: datetime-local already draws its
              own picker button, and a lucide one beside it reads as two
              calendars. Matches ticket-category-form and
              schedule-settings-dialog. */}
          <Input
            type="datetime-local"
            value={when}
            onChange={(e) => setWhen(e.target.value)}
            className="h-9 w-[13rem]"
            aria-label={`Tanggal & jam pertandingan (${tzLabel(tz)})`}
          />
          <span className="text-[11px] font-semibold text-muted-foreground">{tzLabel(tz)}</span>
        </div>
        <div className="min-w-[10rem] flex-1">
          <CourtSelect
            value={venue || null}
            onChange={(v) => setVenue(v ?? "")}
            courts={courts}
            className="h-9"
            placeholder="Lokasi / lapangan (opsional)"
            ariaLabel="Lokasi pertandingan"
          />
        </div>
      </div>
      {/* "untuk publik" in the placeholder, not a tooltip: this is the one moment
          the organizer decides what to type, and the note's whole point is that
          visitors read it. */}
      <Textarea
        value={notes}
        onChange={(e) => setNotes(e.target.value)}
        rows={2}
        maxLength={500}
        className="min-h-0 text-sm"
        placeholder="Catatan untuk publik (opsional) — mis. laga dipindah ke lapangan 2"
        aria-label="Catatan pertandingan (tampil di halaman publik)"
      />
      {dirty && (
        <div className="flex justify-end">
          <Button size="sm" variant="outline" onClick={() => save.mutate()} disabled={save.isPending}>
            <Check className="h-4 w-4" />
            {save.isPending ? "…" : "Simpan jadwal"}
          </Button>
        </div>
      )}
    </div>
  );
}
