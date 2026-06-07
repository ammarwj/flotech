"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";

import { getMatchStats, saveMatchStats, type MatchStatEntry } from "@/lib/api/matches";
import { parseApiError } from "@/lib/api/errors";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import type { MatchRoster, StatColumn } from "@/types/api";

/** Inline editor for per-player match statistics (columns vary by sport). */
export function MatchStatsEditor({
  orgId,
  eventId,
  matchId,
}: {
  orgId: string;
  eventId: string;
  matchId: string;
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

  const teamBlock = (team: MatchRoster | null, cols: StatColumn[]) =>
    team && (
      <div className="min-w-0 flex-1">
        <div className="mb-2 truncate text-xs font-bold text-muted-foreground">{team.name}</div>
        {team.players.length === 0 ? (
          <p className="text-xs text-muted-foreground">Belum ada pemain terdaftar.</p>
        ) : (
          <table className="w-full text-sm">
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
      <div className="flex flex-col gap-6 sm:flex-row">
        {teamBlock(data?.home_team ?? null, columns)}
        {teamBlock(data?.away_team ?? null, columns)}
      </div>
      <div className="mt-3 flex justify-end">
        <Button size="sm" onClick={() => save.mutate()} disabled={save.isPending}>
          {save.isPending ? "Menyimpan…" : "Simpan statistik"}
        </Button>
      </div>
    </div>
  );
}
