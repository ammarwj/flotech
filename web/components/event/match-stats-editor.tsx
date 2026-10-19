"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";

import type { MatchStatEntry, OfficialStatEntry } from "@/lib/api/matches";
import type { MatchStatsGateway } from "@/lib/match-doors";
import { parseApiError } from "@/lib/api/errors";
import { useCatalog } from "@/lib/hooks/use-catalog";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { cn } from "@/lib/utils";
import type { Match, MatchRoster, SportDef, StatColumn } from "@/types/api";

/**
 * Inline editor for per-player match statistics (columns vary by sport).
 *
 * For goal-based sports it keeps the scorers honest against the scoreline: each
 * squad shows how many of its goals are already accounted for, and a mismatch is
 * called out. It's a warning, not a block — an own goal legitimately leaves a
 * goal with no scorer on that side.
 *
 * Two surfaces mount it — the organizer's schedule and the match staff's — so it
 * knows nothing about which one it is on beyond the gateway handed to it.
 *
 * Each squad is followed by its bench, and the bench gets **only** the card
 * columns — `card_columns` from the server, never a filter written here. A coach
 * does not score: the write door refuses a gol for the bench, so offering the
 * column would be offering a refusal, and the goals-vs-score tally below is
 * deliberately blind to officials for the same reason.
 */
export function MatchStatsEditor({
  gateway,
  match,
  sport,
}: {
  /** Which door this editor writes through — see {@link MatchStatsGateway}. */
  gateway: MatchStatsGateway;
  /** The fixture being edited; enables the goals-vs-score cross-check. */
  match?: Match;
  /**
   * Names the bench roles. Required rather than optional-with-a-default, the
   * reason already written for `PublicMatchCard`: a default would let a caller
   * that forgot it render a bench whose rows are told apart by nothing, which
   * looks identical to one that works until two coaches share a name.
   */
  sport: SportDef | null;
}) {
  const qc = useQueryClient();
  const { officialRoleLabel } = useCatalog();
  const statsQuery = useQuery({
    queryKey: gateway.queryKey,
    queryFn: () => gateway.load(),
  });

  // Edits override the fetched tally; key = `${playerId}:${statKey}`.
  const [edits, setEdits] = useState<Record<string, number>>({});
  // The bench's own edit map. Separate because the two id spaces are separate
  // tables: one map keyed on a bare id could not tell a coach from a player, and
  // the save loop would post each under the wrong key.
  const [benchEdits, setBenchEdits] = useState<Record<string, number>>({});
  const data = statsQuery.data;
  const columns = data?.columns ?? [];
  const cardColumns = data?.card_columns ?? [];

  const valueFor = (pid: string, key: string) =>
    edits[`${pid}:${key}`] ?? data?.stats[pid]?.[key] ?? 0;
  const setValue = (pid: string, key: string, v: number) =>
    setEdits((s) => ({ ...s, [`${pid}:${key}`]: v }));

  const benchValueFor = (oid: string, key: string) =>
    benchEdits[`${oid}:${key}`] ?? data?.official_stats[oid]?.[key] ?? 0;
  const setBenchValue = (oid: string, key: string, v: number) =>
    setBenchEdits((s) => ({ ...s, [`${oid}:${key}`]: v }));

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

      // Sent as `undefined` for a sport with no cards, which leaves the bench's
      // rows untouched rather than clearing them — there is no bench block on
      // screen to have cleared anything. With cards it is always an array, so an
      // emptied input really does delete the row.
      let officials: OfficialStatEntry[] | undefined;

      if (cardColumns.length > 0) {
        officials = [];
        for (const o of [
          ...(data?.home_team?.officials ?? []),
          ...(data?.away_team?.officials ?? []),
        ]) {
          for (const c of cardColumns) {
            const v = benchValueFor(o.id, c.key);
            if (v > 0) officials.push({ team_official_id: o.id, stat_key: c.key, value: v });
          }
        }
      }

      return gateway.save(entries, officials);
    },
    onSuccess: () => {
      toast.success("Statistik disimpan");
      // Prefixes, not whole keys: the discipline query carries a category id
      // this editor never sees, and a card that just wrote a yellow must not
      // keep showing the ban list from before it.
      for (const key of gateway.invalidate) {
        qc.invalidateQueries({ queryKey: key });
      }
      setEdits({});
      setBenchEdits({});
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

  /**
   * The bench's card inputs, under the squad they sit with.
   *
   * Rendered as its own table rather than extra rows in the squad's: the columns
   * differ (cards only), and a shared table would need either blank cells where
   * a coach has no gol column or a second header row — both of which read as a
   * player whose stats nobody filled in. Its heading says "Ofisial" so the
   * narrower row of inputs is explained rather than discovered.
   *
   * Nothing renders for a sport without cards (`card_columns` empty) or a team
   * with no bench — the second is why this is not simply gated once at the top.
   */
  const benchBlock = (team: MatchRoster | null) => {
    if (!team || cardColumns.length === 0 || team.officials.length === 0) return null;

    return (
      <div className="mt-4 border-t border-dashed border-border pt-3">
        <div className="mb-2 text-[11px] font-bold uppercase tracking-wide text-muted-foreground">
          Ofisial
        </div>
        <table className="w-full min-w-[16rem] text-sm">
          <thead>
            <tr className="text-[11px] uppercase text-muted-foreground">
              <th className="pb-1 text-left font-medium">Nama</th>
              {cardColumns.map((c) => (
                <th key={c.key} title={c.label} className="w-10 px-0.5 pb-1 text-center font-medium">
                  {c.short}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {team.officials.map((o) => (
              <tr key={o.id}>
                <td className="max-w-[140px] py-1 pr-2">
                  <span className="block truncate">{o.full_name}</span>
                  {/* Through the catalogue, never hardcoded — an admin may
                      rename a role. A bench row has no number, so this is the
                      only thing telling two coaches apart. */}
                  <span className="block truncate text-[11px] text-muted-foreground">
                    {officialRoleLabel(sport?.slug, o.role) || "Ofisial"}
                  </span>
                </td>
                {cardColumns.map((c) => (
                  <td key={c.key} className="px-0.5 py-1">
                    <Input
                      type="number"
                      min={0}
                      value={benchValueFor(o.id, c.key) || ""}
                      onChange={(e) =>
                        setBenchValue(o.id, c.key, e.target.value ? Number(e.target.value) : 0)
                      }
                      className="h-8 w-11 px-1 text-center"
                      aria-label={`${c.label} ${o.full_name}`}
                    />
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
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
        {benchBlock(team)}
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
