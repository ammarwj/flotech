import { standingsColumns } from "@/lib/scoring";
import type { StandingsContext, Standing } from "@/types/api";

/**
 * League table. `highlight` marks the top N rows in green.
 *
 * What N means is the caller's business, and it differs by format: in a hybrid
 * group it's "qualifies for the knockout", in a standalone league there is no
 * next stage to qualify for (generateKnockout() is hybrid-only, 422 otherwise)
 * so the only thing worth marking is the leader.
 *
 * Which columns follow "Tim" — Poin among them — is `context`'s business, see
 * standingsColumns(). Nothing about a sport is decided here; the only thing
 * this table knows about Poin is that it gets the loud type.
 */
export function StandingsTable({
  standings,
  highlight = 0,
  context = "goal",
}: {
  standings: Standing[];
  highlight?: number;
  /** The table's shape: counts gol, game, or partai. */
  context?: StandingsContext;
}) {
  const columns = standingsColumns(context);

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
            {columns.map((c) => (
              <th
                key={c.key}
                className={`whitespace-nowrap px-2 py-3 text-center font-semibold${
                  c.key === "points" ? " text-foreground" : ""
                }`}
              >
                {c.short}
              </th>
            ))}
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
              {columns.map((c) => (
                <td
                  key={c.key}
                  className={`whitespace-nowrap px-2 py-3 text-center${
                    c.key === "points" ? " font-extrabold" : ""
                  }`}
                  style={c.key === "points" ? { fontFamily: "var(--font-display)" } : undefined}
                >
                  {c.cell(s)}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
