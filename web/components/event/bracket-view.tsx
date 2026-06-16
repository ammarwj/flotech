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
    <div className={cn("bkt-side", !name && "bkt-tbd", decided && (isWinner ? "win" : "lose"))}>
      <span className="bkt-crest" style={{ background: name ? crestGradient(name) : "var(--border)" }} />
      <span className="bkt-name">{name ?? "TBD"}</span>
      {score !== null && <span className="bkt-score">{score}</span>}
    </div>
  );
}

/** Single-elimination bracket: one column per round, joined by connector lines. */
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
    <div className="bkt">
      {rounds.map(([round, list]) => (
        <div key={round} className="bkt-col">
          <div className="bkt-head">{(roundLabel ?? knockoutRoundLabel)(list.length, round)}</div>
          <div className="bkt-body">
            {list.map((m) => {
              const winner = matchWinnerId(m);
              const decided = winner !== null;
              // A lone team is a real "Bye" only once the match is settled.
              const awayBye = !!m.home_team_id && !m.away_team_id && m.status === "finished";
              return (
                <div key={m.id} className="bkt-cell">
                  <div className="bkt-match">
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
                </div>
              );
            })}
          </div>
        </div>
      ))}
    </div>
  );
}
