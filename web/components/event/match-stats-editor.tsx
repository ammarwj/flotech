"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";

import { getMatchStats, saveMatchStats, type MatchStatEntry } from "@/lib/api/matches";
import { parseApiError } from "@/lib/api/errors";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { cn } from "@/lib/utils";
import type { Match, MatchRoster, StatColumn } from "@/types/api";

/**
 * Inline editor for per-player match statistics (columns vary by sport).
 *
 * For goal-based sports it keeps the scorers honest against the scoreline: each
 * squad shows how many of its goals are already accounted for, and a mismatch is
 * called out. It's a warning, not a block — an own goal legitimately leaves a
 * goal with no scorer on that side.
 */
export function MatchStatsEditor({
  orgId,
  eventId,
  matchId,
  match,
}: {
  orgId: string;
  eventId: string;
  matchId: string;
  /** The fixture being edited; enables the goals-vs-score cross-check. */
  match?: Match;
}) {
  const qc = useQueryClient();
  const statsQuery = useQuery({
    queryKey: ["match-stats", orgId, matchId],
    queryFn: () => getMatchStats(orgId, matchId),
  });

  // Edits override the fetched tally; key = `${playerId}:${statKey}`.
  const [edits, setEdits] = useState<Record<string, number>>({});
  const data = statsQuery.data;
  const columns = data?.columns ?? [];

  const valueFor = (pid: string, key: string) =>
    edits[`${pid}:${key}`] ?? data?.stats[pid]?.[key] ?? 0;
  const setValue = (pid: string, key: string, v: number) =>
    setEdits((s) => ({ ...s, [`${pid}:${key}`]: v }));

  const save = useMutation({
    mutationFn: () => {
      const players = [
        ...(data?.home_team?.players ?? []),
        ...(data?.away_team?.players ?? []),
      ];
      const entries: MatchStatEntry[] = [];
      for (const p of players) {
        for (const c of columns) {
          const v = valueFor(p.id, c.key);
          if (v > 0) entries.push({ player_id: p.id, stat_key: c.key, value: v });
        }
      }
      return saveMatchStats(orgId, matchId, entries);
    },
    onSuccess: () => {
      toast.success("Statistik disimpan");
      qc.invalidateQueries({ queryKey: ["leaderboard", orgId, eventId] });
      qc.invalidateQueries({ queryKey: ["match-stats", orgId, matchId] });
      setEdits({});
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal menyimpan statistik.").message),
  });

  if (statsQuery.isLoading) {
    return <div className="px-3 py-2 text-xs text-muted-foreground">Memuat pemain…</div>;
  }

  // Which column *is* the score, and which one is the assist, is per-sport data
  // (a sport declares the role of each stat), so this works for any sport the
  // admin adds — not just football.
  const goalColumn = columns.find((c) => c.role === "goal")?.key ?? null;
  const assistColumn = columns.find((c) => c.role === "assist")?.key ?? null;

  const hasAssists = assistColumn !== null;

  /** A squad's running total for one stat, including unsaved edits. */
  const totalOf = (team: MatchRoster | null, key: string) =>
    team ? team.players.reduce((sum, p) => sum + valueFor(p.id, key), 0) : 0;

  const scoreOf = (side: "home" | "away") =>
    match && match.status === "finished" ? match[`${side}_score`] : null;

  const homeGoals = goalColumn ? totalOf(data?.home_team ?? null, goalColumn) : 0;
  const awayGoals = goalColumn ? totalOf(data?.away_team ?? null, goalColumn) : 0;
  const homeScore = scoreOf("home");
  const awayScore = scoreOf("away");

  const homeAssists = assistColumn ? totalOf(data?.home_team ?? null, assistColumn) : 0;
  const awayAssists = assistColumn ? totalOf(data?.away_team ?? null, assistColumn) : 0;

  const mismatch =
    goalColumn !== null &&
    ((homeScore !== null && homeGoals !== homeScore) ||
      (awayScore !== null && awayGoals !== awayScore));

  // A goal carries at most one assist, so a squad can never assist more than it
  // scored. Unlike the scorer mismatch (own goals), this is simply impossible —
  // so it blocks saving.
  const goalCeiling = (score: number | null, scored: number) => score ?? scored;
  const tooManyAssists =
    hasAssists &&
    (homeAssists > goalCeiling(homeScore, homeGoals) ||
      awayAssists > goalCeiling(awayScore, awayGoals));

  /** Small tally chip, e.g. "2/3 gol" — green when it adds up, else amber. */
  const chip = (label: string, ok: boolean) => (
    <span
      className={cn(
        "shrink-0 rounded-full px-2 py-0.5 text-[11px] font-bold",
        ok ? "text-[var(--success)]" : "text-[var(--warning)]"
      )}
      style={{
        background: ok
          ? "color-mix(in srgb, var(--success) 12%, transparent)"
          : "color-mix(in srgb, var(--warning) 12%, transparent)",
      }}
    >
      {label}
    </span>
  );

  const tallies = (side: "home" | "away") => {
    const goals = side === "home" ? homeGoals : awayGoals;
    const score = side === "home" ? homeScore : awayScore;
    const assists = side === "home" ? homeAssists : awayAssists;
    const ceiling = goalCeiling(score, goals);

    return (
      <>
        {goalColumn && score !== null && chip(`${goals}/${score} gol`, goals === score)}
        {hasAssists && assists > 0 && chip(`${assists}/${ceiling} assist`, assists <= ceiling)}
      </>
    );
  };

  const teamBlock = (team: MatchRoster | null, cols: StatColumn[], side: "home" | "away") =>
    team && (
      <div className="min-w-0 flex-1 overflow-x-auto">
        <div className="mb-2 flex items-center gap-2">
          <span className="truncate text-xs font-bold text-muted-foreground">{team.name}</span>
          {tallies(side)}
        </div>
        {team.players.length === 0 ? (
          <p className="text-xs text-muted-foreground">Belum ada pemain terdaftar.</p>
        ) : (
          <table className="w-full min-w-[16rem] text-sm">
            <thead>
              <tr className="text-[11px] uppercase text-muted-foreground">
                <th className="pb-1 text-left font-medium">Pemain</th>
                {cols.map((c) => (
                  <th key={c.key} title={c.label} className="w-10 px-0.5 pb-1 text-center font-medium">
                    {c.short}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {team.players.map((p) => (
                <tr key={p.id}>
                  <td className="max-w-[140px] truncate py-1 pr-2">
                    {p.jersey_number ? `#${p.jersey_number} ` : ""}
                    {p.full_name}
                  </td>
                  {cols.map((c) => (
                    <td key={c.key} className="px-0.5 py-1">
                      <Input
                        type="number"
                        min={0}
                        value={valueFor(p.id, c.key) || ""}
                        onChange={(e) => setValue(p.id, c.key, e.target.value ? Number(e.target.value) : 0)}
                        className="h-8 w-11 px-1 text-center"
                        aria-label={`${c.label} ${p.full_name}`}
                      />
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    );

  return (
    <div className="mt-2 rounded-lg border border-dashed border-border bg-[var(--surface-2)] p-3">
      {/* Squads sit side by side only once there's room; below that they stack. */}
      <div className="flex flex-col gap-6 lg:flex-row">
        {teamBlock(data?.home_team ?? null, columns, "home")}
        {teamBlock(data?.away_team ?? null, columns, "away")}
      </div>

      {match && match.status !== "finished" && goalColumn && (
        <p className="mt-3 text-xs text-muted-foreground">
          Simpan skor pertandingan dulu supaya pencetak gol bisa dicocokkan dengan skor.
        </p>
      )}

      <div className="mt-3 flex flex-wrap items-center justify-end gap-3">
        {tooManyAssists ? (
          <p className="mr-auto text-xs font-medium text-destructive">
            Assist tidak boleh lebih banyak dari gol tim — satu gol maksimal satu assist.
          </p>
        ) : (
          mismatch && (
            <p className="mr-auto text-xs text-[var(--warning)]">
              Pencetak gol belum cocok dengan skor {homeScore}–{awayScore}. Abaikan jika selisihnya
              gol bunuh diri.
            </p>
          )
        )}
        <Button
          size="sm"
          onClick={() => save.mutate()}
          disabled={save.isPending || tooManyAssists}
        >
          {save.isPending ? "Menyimpan…" : "Simpan statistik"}
        </Button>
      </div>
    </div>
  );
}
