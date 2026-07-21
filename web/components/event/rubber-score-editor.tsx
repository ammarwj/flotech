"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Plus, X } from "lucide-react";
import { toast } from "sonner";

import { getRegistrations } from "@/lib/api/events";
import { updateRubber } from "@/lib/api/matches";
import { parseApiError } from "@/lib/api/errors";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import type { Match, MatchRubber, Team } from "@/types/api";

type SetInput = { home: string; away: string };

const toInputs = (sets: { home: number; away: number }[] | null): SetInput[] =>
  sets?.length
    ? sets.map((s) => ({ home: String(s.home), away: String(s.away) }))
    : [{ home: "", away: "" }];

const cleanSets = (sets: SetInput[]) =>
  sets
    .filter((s) => s.home !== "" && s.away !== "")
    .map((s) => ({ home: Number(s.home), away: Number(s.away) }));

const setsWon = (sets: { home: number; away: number }[]) => ({
  home: sets.filter((s) => s.home > s.away).length,
  away: sets.filter((s) => s.away > s.home).length,
});

/**
 * Result editor for a squad tie: one card per partai, with its lineup and its
 * set scores.
 *
 * The tie's own scoreline is never typed — "Spanyol 3-0 Argentina" is what the
 * partai add up to, so it is shown at the top as a read-only tally and the
 * backend recomputes it on every save.
 */
export function RubberScoreEditor({
  orgId,
  eventId,
  match,
}: {
  orgId: string;
  eventId: string;
  match: Match;
}) {
  const rubbers = match.rubbers ?? [];

  // Lineups are picked from each squad's roster, which the schedule page doesn't
  // otherwise need. One fetch for the whole event, shared by every tie on it.
  const { data: teams } = useQuery({
    queryKey: ["registrations", orgId, eventId],
    queryFn: () => getRegistrations(orgId, eventId),
  });

  const rosterOf = (teamId: string | null): Team["players"] =>
    (teams?.find((t) => t.id === teamId)?.players ?? []) as Team["players"];

  const won = rubbers.reduce(
    (acc, r) => {
      if (r.home_score === null || r.away_score === null) return acc;
      if (r.home_score > r.away_score) acc.home++;
      else if (r.away_score > r.home_score) acc.away++;
      return acc;
    },
    { home: 0, away: 0 }
  );

  if (rubbers.length === 0) {
    return (
      <p className="text-sm text-muted-foreground">
        Belum ada partai untuk pertandingan ini. Atur daftar partai di kategori event.
      </p>
    );
  }

  return (
    <div className="grid gap-3">
      <div className="flex items-center gap-3 text-sm">
        <span className="flex-1 truncate text-right font-semibold">{match.home_team?.name}</span>
        <span
          className="font-extrabold tabular-nums"
          style={{ fontFamily: "var(--font-display)" }}
          aria-label="Skor partai"
        >
          {won.home} – {won.away}
        </span>
        <span className="flex-1 truncate font-semibold">{match.away_team?.name}</span>
      </div>

      {rubbers.map((rubber) => (
        <RubberCard
          key={rubber.id}
          orgId={orgId}
          eventId={eventId}
          rubber={rubber}
          homeRoster={rosterOf(match.home_team_id)}
          awayRoster={rosterOf(match.away_team_id)}
        />
      ))}
    </div>
  );
}

function RubberCard({
  orgId,
  eventId,
  rubber,
  homeRoster,
  awayRoster,
}: {
  orgId: string;
  eventId: string;
  rubber: MatchRubber;
  homeRoster: Team["players"];
  awayRoster: Team["players"];
}) {
  const qc = useQueryClient();
  const slots = rubber.type === "double" ? 2 : 1;

  const [home, setHome] = useState<string[]>(() => padLineup(rubber.home_player_ids, slots));
  const [away, setAway] = useState<string[]>(() => padLineup(rubber.away_player_ids, slots));
  const [sets, setSets] = useState<SetInput[]>(() => toInputs(rubber.sets));

  const clean = cleanSets(sets);
  const tally = setsWon(clean);

  const save = useMutation({
    mutationFn: () =>
      updateRubber(orgId, rubber.id, {
        // A half-filled lineup is left unset rather than sent short — the API
        // takes a complete lineup or none at all.
        home_player_ids: home.every(Boolean) ? home : [],
        away_player_ids: away.every(Boolean) ? away : [],
        sets: clean,
      }),
    onSuccess: () => {
      toast.success("Skor partai disimpan");
      qc.invalidateQueries({ queryKey: ["matches", orgId, eventId] });
      qc.invalidateQueries({ queryKey: ["standings", orgId, eventId] });
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal menyimpan skor partai.").message),
  });

  const setVal = (i: number, side: "home" | "away", v: string) =>
    setSets((s) => s.map((x, j) => (j === i ? { ...x, [side]: v } : x)));

  return (
    <div className="grid gap-2 rounded-lg border border-border p-2.5">
      <div className="flex items-center justify-between gap-2">
        <p className="text-sm font-semibold">{rubber.label}</p>
        <span className="text-xs tabular-nums text-muted-foreground">
          {clean.length > 0 ? `${tally.home} – ${tally.away}` : "belum dimainkan"}
        </span>
      </div>

      <div className="grid gap-1.5 sm:grid-cols-2">
        <LineupPicker
          label={rubber.type === "double" ? "Pasangan tuan rumah" : "Tuan rumah"}
          value={home}
          roster={homeRoster}
          onChange={setHome}
        />
        <LineupPicker
          label={rubber.type === "double" ? "Pasangan tamu" : "Tamu"}
          value={away}
          roster={awayRoster}
          onChange={setAway}
        />
      </div>

      {sets.map((s, i) => (
        <div key={i} className="flex items-center justify-center gap-2">
          <span className="w-12 text-xs text-muted-foreground">Set {i + 1}</span>
          <Input
            type="number"
            min={0}
            value={s.home}
            onChange={(e) => setVal(i, "home", e.target.value)}
            className="h-8 w-14 text-center"
            aria-label={`${rubber.label} set ${i + 1} tuan rumah`}
          />
          <span className="text-xs text-muted-foreground">–</span>
          <Input
            type="number"
            min={0}
            value={s.away}
            onChange={(e) => setVal(i, "away", e.target.value)}
            className="h-8 w-14 text-center"
            aria-label={`${rubber.label} set ${i + 1} tamu`}
          />
          <button
            type="button"
            onClick={() => setSets((arr) => (arr.length > 1 ? arr.filter((_, j) => j !== i) : arr))}
            className="text-muted-foreground hover:text-destructive disabled:opacity-40"
            disabled={sets.length <= 1}
            aria-label={`Hapus set ${i + 1} ${rubber.label}`}
          >
            <X className="h-4 w-4" />
          </button>
        </div>
      ))}

      <div className="flex items-center justify-between">
        <Button
          type="button"
          size="sm"
          variant="ghost"
          onClick={() => setSets((s) => [...s, { home: "", away: "" }])}
          disabled={sets.length >= 7}
        >
          <Plus className="h-4 w-4" />
          Tambah set
        </Button>
        <Button size="sm" onClick={() => save.mutate()} disabled={save.isPending}>
          {save.isPending ? "Menyimpan…" : "Simpan partai"}
        </Button>
      </div>
    </div>
  );
}

/** Exactly as many select boxes as the partai has slots — 1 tunggal, 2 ganda. */
function LineupPicker({
  label,
  value,
  roster,
  onChange,
}: {
  label: string;
  value: string[];
  roster: Team["players"];
  onChange: (next: string[]) => void;
}) {
  // Player.id is optional because the type is shared with the roster *form*,
  // where a new row has none yet. A lineup can only reference a saved player.
  const players = (roster ?? []).filter((p): p is typeof p & { id: string } => !!p.id);

  return (
    <div className="grid gap-1">
      <span className="text-xs text-muted-foreground">{label}</span>
      {value.map((id, i) => (
        <Select
          key={i}
          value={id}
          aria-label={`${label} pemain ${i + 1}`}
          onChange={(e) => onChange(value.map((v, j) => (j === i ? e.target.value : v)))}
        >
          <option value="">Pilih pemain…</option>
          {players.map((p) => (
            // A player can't partner themselves, so hide whoever is already
            // picked in the other slot of this same lineup.
            <option key={p.id} value={p.id} disabled={value.includes(p.id) && value[i] !== p.id}>
              {p.full_name}
            </option>
          ))}
        </Select>
      ))}
    </div>
  );
}

function padLineup(ids: string[] | null | undefined, slots: number): string[] {
  return Array.from({ length: slots }, (_, i) => ids?.[i] ?? "");
}
