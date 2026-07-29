"use client";

import { Badge } from "@/components/ui/badge";
import { crestGradient } from "@/lib/bracket";
import { disciplineStatDefs } from "@/lib/scoring";
import type { DisciplinePlayer, DisciplineRules, SportDef } from "@/types/api";

function initials(name: string) {
  return name
    .split(" ")
    .slice(0, 2)
    .map((w) => w[0])
    .join("")
    .toUpperCase();
}

/**
 * Every player who has been booked in this category, and where that leaves
 * them: serving a ban, one card away from one, or in the clear.
 *
 * Sibling of LeaderboardTable and rendered in the same Statistik tab on both
 * the organizer and the public page — the leaderboard answers "who scored", and
 * this answers "who may not play". Both read totals the server derives, so the
 * two tabs cannot show different card counts for the same player.
 *
 * "Suspended" is worked out here from bans_remaining rather than sent as a
 * field. Storing it would give a status that outlives the card behind it: the
 * stat rows of a match are deleted and rewritten on every save, so an organizer
 * correcting three yellows down to two emits nothing a stored status could
 * listen for. See DisciplineService.
 */
export function DisciplineTable({
  players,
  sport,
  rules,
}: {
  players: DisciplinePlayer[];
  /** Names the card columns; the sport owns its own labels. */
  sport: SportDef | null;
  rules: DisciplineRules | null;
}) {
  if (players.length === 0) {
    return (
      <p className="text-sm text-muted-foreground">
        Belum ada kartu yang tercatat di kategori ini.
      </p>
    );
  }

  const cards = disciplineStatDefs(sport);
  const yellowLabel = cards.yellow?.label ?? "Kartu kuning";
  const threshold = rules?.yellow_threshold ?? 0;

  return (
    <div className="grid gap-3">
      <div className="overflow-x-auto rounded-xl border border-border">
        <table className="w-full text-sm">
          <thead>
            <tr className="bg-[var(--surface-2)] text-xs uppercase tracking-wide text-muted-foreground">
              <th className="px-3 py-3 text-left font-semibold">Pemain</th>
              <th className="hidden px-3 py-3 text-left font-semibold sm:table-cell">Tim</th>
              <th className="px-2 py-3 text-center font-semibold" title={yellowLabel}>
                {cards.yellow?.short ?? "KK"}
              </th>
              <th className="px-2 py-3 text-center font-semibold" title={cards.red?.label}>
                {cards.red?.short ?? "KM"}
              </th>
              <th className="px-3 py-3 text-left font-semibold">Status</th>
            </tr>
          </thead>
          <tbody>
            {players.map((p) => (
              <tr key={p.player_id} className="border-t border-border">
                <td className="px-3 py-3">
                  <div className="flex items-center gap-2.5">
                    <span
                      className="grid h-8 w-8 shrink-0 place-items-center rounded-full text-[11px] font-bold text-white"
                      style={{ background: crestGradient(p.player_name) }}
                    >
                      {initials(p.player_name)}
                    </span>
                    <div className="min-w-0">
                      <div className="truncate font-semibold">
                        {p.jersey_number && (
                          <span className="mr-1.5 font-mono text-xs font-normal text-muted-foreground">
                            #{p.jersey_number}
                          </span>
                        )}
                        {p.player_name}
                      </div>
                      <div className="truncate text-xs text-muted-foreground sm:hidden">
                        {p.team_name}
                      </div>
                    </div>
                  </div>
                </td>
                <td className="hidden px-3 py-3 text-muted-foreground sm:table-cell">
                  {p.team_name}
                </td>
                <td className="px-2 py-3 text-center font-semibold">{p.yellow_total}</td>
                <td className="px-2 py-3 text-center font-semibold">{p.red_total}</td>
                <td className="px-3 py-3">
                  <PlayerStatus player={p} threshold={threshold} yellowLabel={yellowLabel} />
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <p className="text-xs text-muted-foreground">
        Larangan dianggap dijalani di pertandingan resmi berikutnya tim tersebut. Panitia
        yang memutuskan siapa yang turun — ini catatan, bukan penguncian.
      </p>
    </div>
  );
}

/**
 * Where a player stands right now.
 *
 * The middle case is the one worth having: a player on the brink is exactly who
 * a coach wants flagged, and it is invisible in the totals column because
 * yellow_running resets on every ban while yellow_total never does.
 */
function PlayerStatus({
  player: p,
  threshold,
  yellowLabel,
}: {
  player: DisciplinePlayer;
  threshold: number;
  yellowLabel: string;
}) {
  if (p.bans_remaining > 0) {
    return (
      <Badge variant="warning" dot>
        Suspended · {p.bans_remaining} laga
      </Badge>
    );
  }

  if (threshold > 0 && p.yellow_running === threshold - 1) {
    return (
      <span className="text-xs text-[var(--warning)]">
        1 {yellowLabel.toLowerCase()} lagi kena larangan
      </span>
    );
  }

  return <span className="text-xs text-muted-foreground">Boleh bermain</span>;
}
