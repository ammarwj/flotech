import type { Match, SwissState } from "@/types/api";

/**
 * Swiss, read from the server's answer rather than re-derived here.
 *
 * Readiness needs *two* numbers, the same way knockoutReadiness() does: "0
 * pending" means either the round is finished or no round exists at all, and
 * those are opposite answers. Rather than keep a second copy of the five rules
 * that decide it — field size, round plan, pending results, exhausted pairings —
 * the endpoint publishes its own verdict and this turns it into the shape a
 * button wants. A second copy would drift, and the drift would look like a
 * dead button with a tooltip saying it should work.
 */
export function swissReadiness(state?: SwissState | null): {
  ready: boolean;
  reason: string | null;
  nextRound: number;
  /** "Ronde 2 dari 5", or null before the first round exists. */
  progress: string | null;
} {
  if (!state) {
    return { ready: false, reason: null, nextRound: 1, progress: null };
  }

  return {
    ready: state.can_add_round,
    reason: state.blocked_reason,
    nextRound: state.next_round,
    progress:
      state.rounds_created > 0
        ? `Ronde ${state.last_round} dari ${state.rounds_planned}`
        : null,
  };
}

export const swissMatches = (matches: Match[]) => matches.filter((m) => m.stage === "swiss");

/**
 * The rounds that rested somebody. A bye is keyed on the *missing opponent*,
 * never on status: the row is stored finished and confirmed so it never holds a
 * round open, which means a status check finds nothing here.
 */
export const swissByes = (matches: Match[]) =>
  swissMatches(matches).filter((m) => !m.home_team || !m.away_team);
