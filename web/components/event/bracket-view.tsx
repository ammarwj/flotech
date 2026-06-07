import { knockoutRoundLabel, matchWinnerId, crestGradient, groupByRound } from "@/lib/bracket";
import { cn } from "@/lib/utils";
import type { Match } from "@/types/api";

function Side({
  name,
  score,
  isWinner,
  decided,
}: {
  name: string | null;
  score: number | null;
  isWinner: boolean;
  decided: boolean;
}) {
  return (
    <div className={cn("row", decided && (isWinner ? "win" : "out"))}>
      <span className="crest" style={{ background: name ? crestGradient(name) : "var(--border)" }} />
      <span className="nm">{name ?? "TBD"}</span>
      <span className="sc">{score ?? ""}</span>
    </div>
  );
}

/** Bracket visualization (uses .ebracket styles). */
export function BracketView({
  matches,
  roundLabel,
}: {
  matches: Match[];
  /** Override the per-column heading. Defaults to single-elim round names. */
  roundLabel?: (matchesInRound: number, round: number) => string;
}) {
  const rounds = groupByRound(matches);
  if (rounds.length === 0) return null;

  return (
    <div className="ebracket">
      {rounds.map(([round, list]) => (
        <div key={round} className="ebracket-col">
          <div className="rnd">{(roundLabel ?? knockoutRoundLabel)(list.length, round)}</div>
          {list.map((m) => {
            const winner = matchWinnerId(m);
            const decided = winner !== null;
            // A lone team is a real "Bye" only once the match is settled.
            const awayBye = !!m.home_team_id && !m.away_team_id && m.status === "finished";
            return (
              <div key={m.id} className="ematch">
                <Side
                  name={m.home_team?.name ?? null}
                  score={m.home_score}
                  isWinner={winner === m.home_team_id}
                  decided={decided}
                />
                <Side
                  name={m.away_team?.name ?? (awayBye ? "Bye" : null)}
                  score={m.away_score}
                  isWinner={winner === m.away_team_id}
                  decided={decided}
                />
              </div>
            );
          })}
        </div>
      ))}
    </div>
  );
}
