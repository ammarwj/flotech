import { Scale } from "lucide-react";

import { standingsColumns } from "@/lib/scoring";
import type { MatchTeamRef, StandingsContext, Standing } from "@/types/api";

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
 *
 * Rows the API marked `needs_decider` say so beside the name. Nothing about
 * their numbers is wrong — the place itself is, because no criterion produced
 * it and the lot did. Given `onDecide`, saying so becomes a button; without one
 * (the public table) it stays a note.
 */
export function StandingsTable({
  standings,
  highlight = 0,
  context = "goal",
  onDecide,
}: {
  standings: Standing[];
  highlight?: number;
  /** The table's shape: counts gol, game, or partai. */
  context?: StandingsContext;
  /**
   * Offer to schedule the decider these rows are owed. Handed every team in the
   * deadlock — usually two, but three can be level all round — plus the group
   * whose table it settles.
   */
  onDecide?: (teams: MatchTeamRef[], group: string | null) => void;
}) {
  const columns = standingsColumns(context);
  const blocks = deadlocks(standings);

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
              <td className="px-3 py-3 font-semibold">
                <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
                  {s.team.name}
                  {s.needs_decider && (
                    <DeciderMark
                      onClick={
                        onDecide
                          ? () => onDecide(blocks.get(s.team.id) ?? [s.team], s.group_name)
                          : undefined
                      }
                    />
                  )}
                </span>
              </td>
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

/**
 * Who is deadlocked with whom. The API flags rows, not pairs, so the grouping is
 * read back off the order it already ranked them in: flagged rows that sit next
 * to each other are the same deadlock, and a run of three is three teams no
 * criterion could tell apart.
 */
function deadlocks(standings: Standing[]): Map<string, MatchTeamRef[]> {
  const out = new Map<string, MatchTeamRef[]>();
  let run: Standing[] = [];

  const close = () => {
    if (run.length > 1) {
      const teams = run.map((r) => r.team);
      for (const r of run) out.set(r.team.id, teams);
    }
    run = [];
  };

  for (const s of standings) {
    if (s.needs_decider) run.push(s);
    else close();
  }
  close();

  return out;
}

/** ⚖ beside a name: this place came from the lot, not from a criterion. */
function DeciderMark({ onClick }: { onClick?: () => void }) {
  const label = "Butuh laga penentuan";

  if (!onClick) {
    return (
      <span className="badge badge-warning" title={label}>
        <Scale className="h-3 w-3" />
        {label}
      </span>
    );
  }

  return (
    <button
      type="button"
      onClick={onClick}
      title="Jadwalkan laga penentuan untuk memisahkan tim ini"
      className="badge badge-warning transition-opacity hover:opacity-80"
    >
      <Scale className="h-3 w-3" />
      {label}
    </button>
  );
}
