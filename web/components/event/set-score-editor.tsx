"use client";

import { useState } from "react";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { Plus, X } from "lucide-react";
import { toast } from "sonner";

import { updateMatchResult } from "@/lib/api/matches";
import { parseApiError } from "@/lib/api/errors";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import type { Match } from "@/types/api";

type SetInput = { home: string; away: string };

/** Set-by-set result editor for racket / volleyball matches. */
export function SetScoreEditor({
  orgId,
  eventId,
  match,
}: {
  orgId: string;
  eventId: string;
  match: Match;
}) {
  const qc = useQueryClient();
  const [sets, setSets] = useState<SetInput[]>(
    match.sets?.length
      ? match.sets.map((s) => ({ home: String(s.home), away: String(s.away) }))
      : [{ home: "", away: "" }]
  );

  const clean = sets
    .filter((s) => s.home !== "" && s.away !== "")
    .map((s) => ({ home: Number(s.home), away: Number(s.away) }));
  const homeWon = clean.filter((s) => s.home > s.away).length;
  const awayWon = clean.filter((s) => s.away > s.home).length;

  const save = useMutation({
    mutationFn: () => updateMatchResult(orgId, match.id, { status: "finished", sets: clean }),
    onSuccess: () => {
      toast.success("Skor disimpan");
      qc.invalidateQueries({ queryKey: ["matches", orgId, eventId] });
      qc.invalidateQueries({ queryKey: ["standings", orgId, eventId] });
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal menyimpan skor.").message),
  });

  const setVal = (i: number, side: "home" | "away", v: string) =>
    setSets((s) => s.map((x, j) => (j === i ? { ...x, [side]: v } : x)));

  return (
    <div className="grid gap-2">
      <div className="flex items-center gap-3 text-sm">
        <span className="flex-1 truncate text-right font-semibold">{match.home_team?.name}</span>
        <span className="font-extrabold tabular-nums" style={{ fontFamily: "var(--font-display)" }}>
          {homeWon} – {awayWon}
        </span>
        <span className="flex-1 truncate font-semibold">{match.away_team?.name}</span>
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
            aria-label={`Set ${i + 1} ${match.home_team?.name}`}
          />
          <span className="text-xs text-muted-foreground">–</span>
          <Input
            type="number"
            min={0}
            value={s.away}
            onChange={(e) => setVal(i, "away", e.target.value)}
            className="h-8 w-14 text-center"
            aria-label={`Set ${i + 1} ${match.away_team?.name}`}
          />
          <button
            type="button"
            onClick={() => setSets((arr) => (arr.length > 1 ? arr.filter((_, j) => j !== i) : arr))}
            className="text-muted-foreground hover:text-destructive disabled:opacity-40"
            disabled={sets.length <= 1}
            aria-label="Hapus set"
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
        <Button size="sm" onClick={() => save.mutate()} disabled={clean.length === 0 || save.isPending}>
          {save.isPending ? "Menyimpan…" : "Simpan skor"}
        </Button>
      </div>
    </div>
  );
}
