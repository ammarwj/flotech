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

/** Single-elimination bracket visualization (uses .ebracket styles). */
export function BracketView({ matches }: { matches: Match[] }) {
  const rounds = groupByRound(matches);
  if (rounds.length === 0) return null;

  return (
    <div className="ebracket">
      {rounds.map(([round, list]) => (
        <div key={round} className="ebracket-col">
          <div className="rnd">{knockoutRoundLabel(list.length)}</div>
          {list.map((m) => {
            const winner = matchWinnerId(m);
            const decided = winner !== null;
            return (
              <div key={m.id} className="ematch">
                <Side
                  name={m.home_team?.name ?? null}
                  score={m.home_score}
                  isWinner={winner === m.home_team_id}
                  decided={decided}
                />
                <Side
                  name={m.away_team?.name ?? (m.home_team_id && !m.away_team_id ? "Bye" : null)}
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
