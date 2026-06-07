import { crestGradient } from "@/lib/bracket";
import { cn } from "@/lib/utils";
import type { Leaderboard } from "@/types/api";

function initials(name: string) {
  return name
    .split(" ")
    .slice(0, 2)
    .map((w) => w[0])
    .join("")
    .toUpperCase();
}

/** Sport-aware player leaderboard with dynamic stat columns. */
export function LeaderboardTable({ leaderboard }: { leaderboard: Leaderboard }) {
  const { columns, primary, rows } = leaderboard;

  if (rows.length === 0) {
    return (
      <p className="text-sm text-muted-foreground">
        Belum ada statistik pemain. Tambahkan dari hasil pertandingan.
      </p>
    );
  }

  return (
    <div className="overflow-x-auto rounded-xl border border-border">
      <table className="w-full text-sm">
        <thead>
          <tr className="bg-[var(--surface-2)] text-xs uppercase tracking-wide text-muted-foreground">
            <th className="w-10 px-2 py-3 text-center font-semibold">#</th>
            <th className="px-3 py-3 text-left font-semibold">Pemain</th>
            {columns.map((c) => (
              <th
                key={c.key}
                title={c.label}
                className={cn("px-2 py-3 text-center font-semibold", c.key === primary && "text-foreground")}
              >
                {c.short}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((r) => (
            <tr key={r.player_id} className="border-t border-border">
              <td className="px-2 py-3 text-center font-mono text-muted-foreground">{r.rank}</td>
              <td className="px-3 py-3">
                <div className="flex items-center gap-2.5">
                  <span
                    className="grid h-8 w-8 shrink-0 place-items-center rounded-full text-[11px] font-bold text-white"
                    style={{ background: crestGradient(r.player_name) }}
                  >
                    {initials(r.player_name)}
                  </span>
                  <div className="min-w-0">
                    <div className="truncate font-semibold">{r.player_name}</div>
                    <div className="truncate text-xs text-muted-foreground">{r.team_name}</div>
                  </div>
                </div>
              </td>
              {columns.map((c) => (
                <td
                  key={c.key}
                  className={cn("px-2 py-3 text-center", c.key === primary ? "font-extrabold" : "text-muted-foreground")}
                  style={c.key === primary ? { fontFamily: "var(--font-display)" } : undefined}
                >
                  {r.stats[c.key] ?? 0}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
