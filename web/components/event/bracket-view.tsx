import { Pencil } from "lucide-react";

import {
  THIRD_PLACE_LABEL,
  knockoutRoundLabel,
  matchWinnerId,
  crestGradient,
  groupByRound,
  isThirdPlace,
  wentToPenalties,
} from "@/lib/bracket";
import { cn } from "@/lib/utils";
import { matchScoreText } from "@/lib/scoring";
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

/**
 * One tie of the draw. Lives outside BracketView because the third-place
 * play-off under the final shows the same card — scoreline, bye, shootout
 * and all.
 */
function MatchCell({
  match: m,
  onEditSlot,
}: {
  match: Match;
  /** Renders the seed-correction affordance. Omitted where a slot is fed. */
  onEditSlot?: (match: Match) => void;
}) {
  const winner = matchWinnerId(m);
  const decided = winner !== null;
  // A lone team is a real "Bye" only once the match is settled.
  const awayBye = !!m.home_team_id && !m.away_team_id && m.status === "finished";
  const pens = wentToPenalties(m);
  // Set/partai detail under the two sides — "21-15, 21-18" for badminton, the
  // played rubbers for a squad tie, undefined (nothing) for goal sports.
  const detail = matchScoreText(m).detail;

  return (
    <div className="bkt-match">
      {onEditSlot && (
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
      {detail && <div className="bkt-sets">{detail}</div>}
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
  // The third-place tie shares the final's round but is not a slot of the tree —
  // giving the final column a second cell would halve the column's slots and
  // pull the final off the mid-line the semifinal connectors meet at. It hangs
  // off the final's own cell instead (see .bkt-cell-third).
  const rounds = groupByRound(matches.filter((m) => !isThirdPlace(m)));
  if (rounds.length === 0) return null;

  // The backend never creates one for a bracket of two, so there is always a
  // final above it to hang it under.
  const third = matches.filter(isThirdPlace);

  // Hand-added friendlies share round 1 with a single-elimination bracket, but
  // their order keeps counting past it — they are not slots and the backend
  // refuses to re-seat them.
  const maxRound = rounds[rounds.length - 1][0];
  const slotCount = 2 ** Math.max(1, maxRound) / 2;

  return (
    <div className="bkt">
      {rounds.map(([round, list], col) => (
        <div key={round} className="bkt-col">
          <div className="bkt-head">{(roundLabel ?? knockoutRoundLabel)(list.length, round)}</div>
          <div className="bkt-body">
            {list.map((m, i) => {
              // The backend refuses a slot whose result is already in, so the
              // affordance must not appear there either.
              const editable =
                onEditSlot &&
                m.round === 1 &&
                m.order < slotCount &&
                m.home_score === null &&
                m.away_score === null;
              // Under the last cell of the last column — the final.
              const withThird =
                third.length > 0 && col === rounds.length - 1 && i === list.length - 1;
              return (
                <div key={m.id} className={cn("bkt-cell group/slot", withThird && "bkt-cell-third")}>
                  <MatchCell match={m} onEditSlot={editable ? onEditSlot : undefined} />
                  {withThird && (
                    // No onEditSlot: both slots are filled by advanceLoser(), so
                    // a team put here by hand would be overwritten the moment a
                    // semifinal is confirmed.
                    <div className="bkt-third">
                      {/* Shares .bkt-head so the heading never drifts from the
                          column labels above it. */}
                      <div className="bkt-head bkt-third-head">{THIRD_PLACE_LABEL}</div>
                      {third.map((t) => (
                        <div key={t.id} className="bkt-third-card">
                          <MatchCell match={t} />
                        </div>
                      ))}
                    </div>
                  )}
                </div>
              );
            })}
          </div>
        </div>
      ))}
    </div>
  );
}
