import {
  getMatchStats,
  saveMatchStats,
  updateMatchResult,
  type MatchResultPayload,
  type MatchStatEntry,
} from "@/lib/api/matches";
import {
  getOfficiatingMatchStats,
  saveOfficiatingMatchStats,
  updateOfficiatingResult,
} from "@/lib/api/officiating";
import type { Match, MatchStatsData } from "@/types/api";

/**
 * The two doors onto one fixture.
 *
 * A result and a stat sheet can be written from the organizer's tenant-scoped
 * routes or from the match staff's `/officiating/...` ones. On the server both
 * land in the same pair of services — MatchResultService and MatchStatService —
 * precisely so the assist ceiling and the bracket propagation have one copy.
 * These gateways are that decision carried into the client: the editors take a
 * door rather than being duplicated per surface, because a second copy is a copy
 * that drifts while nobody is watching.
 *
 * The two differences between the doors are real and neither is cosmetic:
 *
 *  - **No org id exists on the officiating side.** A task account holds no
 *    `organization_members` row, which is what closes the organizer API to it
 *    structurally. The officiating query keys are shaped without one so a stray
 *    organizer key can never accidentally match.
 *  - **The officiating door never confirms.** The server passes
 *    `$autoConfirm = false` there unconditionally — staff record a result, an
 *    org admin ratifies it.
 */

/** Keys to invalidate after a write. Prefixes, matching how the queries nest. */
type Keys = readonly (readonly unknown[])[];

/** Where a stat sheet is read from and written to. */
export interface MatchStatsGateway {
  /** Cache key for this fixture's tally on this surface. */
  queryKey: readonly unknown[];
  load: () => Promise<MatchStatsData>;
  save: (entries: MatchStatEntry[]) => Promise<unknown>;
  /**
   * Whoever writes cards owes the discipline query an invalidation — there is
   * no default here, because a forgotten key leaves a stale ban list on the very
   * screen that just changed it.
   */
  invalidate: Keys;
}

/** Where a scoreline is written. */
export interface MatchResultGateway {
  save: (payload: MatchResultPayload) => Promise<Match>;
  invalidate: Keys;
}

/** The organizer's door: tenant-scoped, and the keys its pages read. */
export function organizerStatsGateway(
  orgId: string,
  eventId: string,
  matchId: string,
): MatchStatsGateway {
  return {
    queryKey: ["match-stats", orgId, matchId],
    load: () => getMatchStats(orgId, matchId),
    save: (entries) => saveMatchStats(orgId, matchId, entries),
    invalidate: [
      ["leaderboard", orgId, eventId],
      ["discipline", orgId, eventId],
      ["match-stats", orgId, matchId],
    ],
  };
}

export function organizerResultGateway(
  orgId: string,
  eventId: string,
  matchId: string,
): MatchResultGateway {
  return {
    save: (payload) => updateMatchResult(orgId, matchId, payload),
    invalidate: [
      ["matches", orgId, eventId],
      ["standings", orgId, eventId],
      ["discipline", orgId, eventId],
    ],
  };
}

/** The match staff's door. */
export function officiatingStatsGateway(
  eventId: string,
  matchId: string,
): MatchStatsGateway {
  return {
    queryKey: ["officiating-match-stats", eventId, matchId],
    load: () => getOfficiatingMatchStats(eventId, matchId),
    save: (entries) => saveOfficiatingMatchStats(eventId, matchId, entries),
    invalidate: [
      ["officiating-discipline", eventId],
      ["officiating-match-stats", eventId, matchId],
    ],
  };
}

export function officiatingResultGateway(
  eventId: string,
  matchId: string,
): MatchResultGateway {
  return {
    save: (payload) => updateOfficiatingResult(eventId, matchId, payload),
    invalidate: [
      ["officiating-matches", eventId],
      ["officiating-discipline", eventId],
    ],
  };
}
