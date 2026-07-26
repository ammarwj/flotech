import type {
  BracketConfig,
  KnockoutPlan,
  KnockoutRound,
  Match,
  StandingsContext,
  Tiebreaker,
} from "@/types/api";

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
 * A singles/doubles match cannot end level — there is no draw to award a point
 * for — so it defaults to a plain 1 per win. Squad ties *can* finish 1-1, so
 * they keep football's 3/1/0. Mirrors HybridConfig::fromArray() on the API.
 */
const defaultPoints = (context: StandingsContext) =>
  context === "set" ? { win: 1, draw: 0, lose: 0 } : DEFAULT_HYBRID.points;

/**
 * @param raw the event's stored bracket_config
 * @param tiebreakers the catalog's tiebreaker keys *for this context*, used when
 *        the event has none — see useCatalog().tiebreakersFor()
 * @param context the standings shape, which decides the points defaults
 */
export function hybridConfig(
  raw?: BracketConfig | null,
  tiebreakers: Tiebreaker[] = [],
  context: StandingsContext = "goal"
): HybridConfig {
  // The stored order, minus what this shape cannot compute. The API already
  // translates the keys it can (see Tiebreakers on the backend) and sends the
  // result, so what's left to do here is the case that never reaches it: the
  // organizer switching sport in the form, where the config in state was
  // written for the shape it had a moment ago.
  //
  // An empty vocabulary means the catalog hasn't arrived yet, not that every
  // key is invalid — filtering against it would blank the tiebreaker legend for
  // a frame on every page that prints one.
  const stored = tiebreakers.length
    ? (raw?.tiebreakers?.filter((t) => tiebreakers.includes(t)) ?? [])
    : (raw?.tiebreakers ?? []);

  // A set-based category cannot draw, so a stored draw point came from another
  // shape's defaults — and the 3 next to it did too. Mirrors HybridConfig on
  // the API, which drops the same trio for the same reason.
  const points = context === "set" && (raw?.points?.draw ?? 0) > 0 ? undefined : raw?.points;

  return {
    ...DEFAULT_HYBRID,
    ...raw,
    points: { ...defaultPoints(context), ...points },
    qualification: { ...DEFAULT_HYBRID.qualification, ...raw?.qualification },
    knockout_start: raw?.knockout_start ?? null,
    tiebreakers: stored.length ? stored : tiebreakers,
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

/**
 * Whether the bracket can be generated yet, and why not when it can't. Mirrors
 * the two gates in MatchController::generateKnockout(), so the button and the
 * banner above it can never disagree with the endpoint behind them.
 *
 * Both counts are load-bearing: zero *pending* means two opposite things — every
 * group game is played, or none exists yet — and the second is refused. Reading
 * the pending count alone is exactly how the plan page came to print "bracket
 * siap dibuat" over a dead button, which is the whole reason this lives in one
 * place now.
 *
 * `reason` is aimed at the button, not the page: the draw itself can always be
 * saved, and reading this as "come back later" sends the organizer away from the
 * one thing they can do right now.
 */
export function knockoutReadiness(plan: KnockoutPlan): {
  ready: boolean;
  reason: string | null;
} {
  if (plan.group_matches_total === 0) {
    return {
      ready: false,
      reason:
        "Fase grup belum punya jadwal, dan bracket disusun dari klasemen; klasemen kosong hanya akan mengurutkan tim berdasarkan nama.",
    };
  }

  if (plan.group_matches_pending > 0) {
    return {
      ready: false,
      reason: `Masih ada ${plan.group_matches_pending} pertandingan grup yang belum selesai atau belum dikonfirmasi.`,
    };
  }

  return { ready: true, reason: null };
}
