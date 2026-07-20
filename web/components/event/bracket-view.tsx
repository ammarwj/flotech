import { Pencil } from "lucide-react";

import {
  knockoutRoundLabel,
  matchWinnerId,
  crestGradient,
  groupByRound,
  isThirdPlace,
  wentToPenalties,
} from "@/lib/bracket";
import { cn } from "@/lib/utils";
import type { Match } from "@/types/api";

function Side({
  name,
  logoUrl,
  score,
  penalty,
  isWinner,
  decided,
}: {
  name: string | null;
  logoUrl?: string | null;
  score: number | null;
  /** Shootout score, shown next to the scoreline when the tie went to penalties. */
  penalty?: number | null;
  isWinner: boolean;
  decided: boolean;
}) {
  return (
    <div className={cn("bkt-side", !name && "bkt-tbd", decided && (isWinner ? "win" : "lose"))}>
      {logoUrl ? (
        // eslint-disable-next-line @next/next/no-img-element
        <img className="bkt-crest" src={logoUrl} alt={name ?? ""} style={{ objectFit: "cover" }} />
      ) : (
        <span className="bkt-crest" style={{ background: name ? crestGradient(name) : "var(--border)" }} />
      )}
      <span className="bkt-name">{name ?? "TBD"}</span>
      {penalty !== null && penalty !== undefined && (
        <span className="bkt-pen" title="Adu penalti">
          ({penalty})
        </span>
      )}
      {score !== null && <span className="bkt-score">{score}</span>}
    </div>
  );
}

/** Single-elimination bracket: one column per round, joined by connector lines. */
export function BracketView({
  matches,
  roundLabel,
  onEditSlot,
}: {
  matches: Match[];
  /** Override the per-column heading. Defaults to single-elim round names. */
  roundLabel?: (matchesInRound: number, round: number) => string;
  /**
   * Organizer-only: correct a seed. Offered on the first round only, because
   * later slots belong to advanceWinner() — a team put there by hand would be
   * overwritten the moment its feeder is confirmed.
   */
  onEditSlot?: (match: Match) => void;
}) {
  // The third-place tie shares the final's round but is not part of the tree —
  // drawing it there would give the final column two cells and a connector to
  // nowhere. The schedule list shows it under its own heading instead.
  const rounds = groupByRound(matches.filter((m) => !isThirdPlace(m)));
  if (rounds.length === 0) return null;

  // Hand-added friendlies share round 1 with a single-elimination bracket, but
  // their order keeps counting past it — they are not slots and the backend
  // refuses to re-seat them.
  const maxRound = rounds[rounds.length - 1][0];
  const slotCount = 2 ** Math.max(1, maxRound) / 2;

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
              const pens = wentToPenalties(m);
              // The backend refuses a slot whose result is already in, so the
              // affordance must not appear there either.
              const editable =
                onEditSlot &&
                m.round === 1 &&
                m.order < slotCount &&
                m.home_score === null &&
                m.away_score === null;
              return (
                <div key={m.id} className="bkt-cell group/slot">
                  <div className="bkt-match">
                    {editable && (
                      <button
                        type="button"
                        onClick={() => onEditSlot(m)}
                        aria-label="Ganti tim di slot ini"
                        title="Ganti tim"
                        className="absolute -right-2 -top-2 z-10 grid h-6 w-6 place-items-center rounded-md border border-border bg-card text-muted-foreground opacity-0 shadow-[var(--shadow-sm)] transition-opacity hover:text-foreground focus-visible:opacity-100 group-hover/slot:opacity-100"
                      >
                        <Pencil className="h-3 w-3" />
                      </button>
                    )}
                    <Side
                      name={m.home_team?.name ?? null}
                      logoUrl={m.home_team?.logo_url}
                      score={m.home_score}
                      penalty={pens ? m.home_penalty : null}
                      isWinner={winner === m.home_team_id}
                      decided={decided}
                    />
                    <Side
                      name={m.away_team?.name ?? (awayBye ? "Bye" : null)}
                      logoUrl={m.away_team?.logo_url}
                      score={m.away_score}
                      penalty={pens ? m.away_penalty : null}
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
