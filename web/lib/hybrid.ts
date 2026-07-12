import type { BracketConfig, KnockoutRound, Match, Tiebreaker } from "@/types/api";

/** Teams held by each knockout entry round. Mirrors HybridConfig on the API. */
export const KNOCKOUT_ROUND_SIZES: Record<KnockoutRound, number> = {
  final: 2,
  semifinal: 4,
  quarter_final: 8,
  round_of_16: 16,
  round_of_32: 32,
  round_of_64: 64,
};

export const TIEBREAKER_ORDER: Tiebreaker[] = [
  "head_to_head",
  "goal_difference",
  "goals_scored",
  "fair_play",
  "drawing_lots",
];

/** A fully-filled config, so the form never has to deal with `undefined`. */
export type HybridConfig = Required<Omit<BracketConfig, "points" | "qualification" | "knockout_start">> & {
  points: { win: number; draw: number; lose: number };
  qualification: { top_per_group: number; best_runners_up: number; best_thirds: number };
  knockout_start: KnockoutRound | null;
};

export const DEFAULT_HYBRID: HybridConfig = {
  groups: 4,
  teams_per_group: 4,
  home_away: false,
  legs: 1,
  points: { win: 3, draw: 1, lose: 0 },
  qualification: { top_per_group: 2, best_runners_up: 0, best_thirds: 0 },
  knockout_start: null,
  draw_method: "random",
  tiebreakers: TIEBREAKER_ORDER,
};

export function hybridConfig(raw?: BracketConfig | null): HybridConfig {
  return {
    ...DEFAULT_HYBRID,
    ...raw,
    points: { ...DEFAULT_HYBRID.points, ...raw?.points },
    qualification: { ...DEFAULT_HYBRID.qualification, ...raw?.qualification },
    knockout_start: raw?.knockout_start ?? null,
    tiebreakers: raw?.tiebreakers?.length ? raw.tiebreakers : TIEBREAKER_ORDER,
  };
}

export const totalTeams = (c: HybridConfig) => c.groups * c.teams_per_group;

/** Teams reaching the knockout stage: the automatic places plus the extras. */
export function qualifierCount(c: HybridConfig): number {
  const { top_per_group, best_runners_up, best_thirds } = c.qualification;
  const extras =
    (top_per_group < 2 ? best_runners_up : 0) + (top_per_group < 3 ? best_thirds : 0);
  return c.groups * top_per_group + extras;
}

/** Bracket size: the chosen entry round, or the next power of two above the field. */
export function bracketSize(c: HybridConfig): number {
  if (c.knockout_start) return KNOCKOUT_ROUND_SIZES[c.knockout_start];
  let size = 2;
  while (size < Math.max(2, qualifierCount(c))) size *= 2;
  return size;
}

/** Slots the bracket can't fill from the qualifiers — they become BYEs. */
export const byeCount = (c: HybridConfig) => Math.max(0, bracketSize(c) - qualifierCount(c));

export const groupNames = (c: HybridConfig): string[] =>
  Array.from({ length: c.groups }, (_, i) => String.fromCharCode(65 + i));

export const groupMatches = (matches: Match[]) => matches.filter((m) => m.stage === "group");
export const knockoutMatches = (matches: Match[]) => matches.filter((m) => m.stage === "knockout");
