import type { BracketConfig, KnockoutRound, Match, Tiebreaker } from "@/types/api";

/**
 * The hybrid format's per-event settings. The *vocabulary* (which tiebreakers,
 * draw methods and knockout rounds exist) comes from the catalog — see
 * useCatalog() — so nothing is mirrored from the backend here any more.
 */
export type HybridConfig = Required<
  Omit<BracketConfig, "points" | "qualification" | "knockout_start">
> & {
  points: { win: number; draw: number; lose: number };
  qualification: { top_per_group: number; best_runners_up: number; best_thirds: number };
  knockout_start: KnockoutRound | null;
};

/** Mirrors HybridConfig's defaults on the API. */
export const DEFAULT_HYBRID: HybridConfig = {
  groups: 4,
  teams_per_group: 4,
  home_away: false,
  legs: 1,
  points: { win: 3, draw: 1, lose: 0 },
  qualification: { top_per_group: 2, best_runners_up: 0, best_thirds: 0 },
  knockout_start: null,
  third_place: false,
  draw_method: "random",
  tiebreakers: [],
};

/**
 * @param raw the event's stored bracket_config
 * @param tiebreakers the catalog's tiebreaker keys, used when the event has none
 */
export function hybridConfig(
  raw?: BracketConfig | null,
  tiebreakers: Tiebreaker[] = []
): HybridConfig {
  return {
    ...DEFAULT_HYBRID,
    ...raw,
    points: { ...DEFAULT_HYBRID.points, ...raw?.points },
    qualification: { ...DEFAULT_HYBRID.qualification, ...raw?.qualification },
    knockout_start: raw?.knockout_start ?? null,
    tiebreakers: raw?.tiebreakers?.length ? raw.tiebreakers : tiebreakers,
  };
}

export const totalTeams = (c: HybridConfig) => c.groups * c.teams_per_group;

/**
 * Teams reaching the knockout stage: the automatic places, plus the extras that
 * aren't already covered by them (a "best runner-up" is meaningless when every
 * runner-up qualifies anyway).
 */
export function qualifierCount(c: HybridConfig): number {
  const { top_per_group, best_runners_up, best_thirds } = c.qualification;
  const extras =
    (top_per_group < 2 ? best_runners_up : 0) + (top_per_group < 3 ? best_thirds : 0);
  return c.groups * top_per_group + extras;
}

/**
 * Bracket size: the chosen entry round, or the next power of two above the
 * field.
 *
 * @param roundSize resolves a knockout round key to its team count (from the catalog)
 */
export function bracketSize(c: HybridConfig, roundSize: (key: string) => number): number {
  if (c.knockout_start) return Math.max(2, roundSize(c.knockout_start));
  let size = 2;
  while (size < Math.max(2, qualifierCount(c))) size *= 2;
  return size;
}

/** Slots the bracket can't fill from the qualifiers — they become BYEs. */
export const byeCount = (c: HybridConfig, roundSize: (key: string) => number) =>
  Math.max(0, bracketSize(c, roundSize) - qualifierCount(c));

export const groupNames = (c: HybridConfig): string[] =>
  Array.from({ length: c.groups }, (_, i) => String.fromCharCode(65 + i));

export const groupMatches = (matches: Match[]) => matches.filter((m) => m.stage === "group");
export const knockoutMatches = (matches: Match[]) => matches.filter((m) => m.stage === "knockout");
