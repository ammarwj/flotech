import type { Standing } from "@/types/api";

/** League table. `highlight` marks the top N rows (e.g. playoff qualifiers). */
export function StandingsTable({ standings, highlight = 0 }: { standings: Standing[]; highlight?: number }) {
  if (standings.length === 0) {
    return (
      <p className="text-sm text-muted-foreground">
        Klasemen muncul setelah ada hasil pertandingan.
      </p>
    );
  }

  return (
    <div className="overflow-x-auto rounded-xl border border-border">
      <table className="w-full text-sm">
        <thead>
          <tr className="bg-[var(--surface-2)] text-xs uppercase tracking-wide text-muted-foreground">
            <th className="w-10 px-2 py-3 text-center font-semibold">#</th>
            <th className="px-3 py-3 text-left font-semibold">Tim</th>
            {["M", "M", "S", "K", "GM", "GK", "SG"].map((h, i) => (
              <th key={i} className="px-2 py-3 text-center font-semibold">
                {h}
              </th>
            ))}
            <th className="px-2 py-3 text-center font-semibold text-foreground">Poin</th>
          </tr>
        </thead>
        <tbody>
          {standings.map((s) => (
            <tr key={s.team.id} className="border-t border-border">
              <td
                className="px-2 py-3 text-center font-mono text-muted-foreground"
                style={
                  highlight > 0 && s.rank <= highlight
                    ? { boxShadow: "inset 3px 0 0 var(--success)", color: "var(--success)" }
                    : undefined
                }
              >
                {s.rank}
              </td>
              <td className="px-3 py-3 font-semibold">{s.team.name}</td>
              <td className="px-2 py-3 text-center">{s.played}</td>
              <td className="px-2 py-3 text-center">{s.won}</td>
              <td className="px-2 py-3 text-center">{s.drawn}</td>
              <td className="px-2 py-3 text-center">{s.lost}</td>
              <td className="px-2 py-3 text-center">{s.goals_for}</td>
              <td className="px-2 py-3 text-center">{s.goals_against}</td>
              <td className="px-2 py-3 text-center">{s.goal_diff > 0 ? `+${s.goal_diff}` : s.goal_diff}</td>
              <td className="px-2 py-3 text-center font-extrabold" style={{ fontFamily: "var(--font-display)" }}>
                {s.points}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
