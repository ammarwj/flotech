import type { SeedPair } from "@/lib/api/matches";
import type { FormatEngine, Match } from "@/types/api";

// Formats are admin-managed presets; what decides behaviour is the engine the
// backend runs them on (see SportEvent.engine).
export function isKnockout(engine: FormatEngine | null | undefined): boolean {
  return engine === "knockout_single" || engine === "knockout_double";
}

export function isDoubleElim(engine: FormatEngine | null | undefined): boolean {
  return engine === "knockout_double";
}

export function isHybrid(engine: FormatEngine | null | undefined): boolean {
  return engine === "hybrid";
}

/**
 * Bracket size for a field of `teams`: the next power of two, at least 2.
 * Mirrors BracketSeeding::sizeFor() — single elimination has no knockout-plan
 * endpoint to read the size from, so the seed editor works it out itself.
 */
export function bracketSizeFor(teams: number): number {
  let size = 2;
  while (size < teams) size *= 2;
  return size;
}

/**
 * Teams the organizer has placed in no slot at all. Mirrors
 * BracketSeeding::unplacedTeams(), which rejects such a payload with a 422:
 * an empty slot is deliberate, a team left out of the bracket never plays.
 *
 * Generic over the team shape so both the seed editor (rendering the warning)
 * and its dialogs (disabling submit) can call it.
 */
export function unplacedTeams<T extends { id: string }>(pool: T[], pairs: SeedPair[]): T[] {
  const placed = new Set(
    pairs.flatMap((p) => [p.home_team_id, p.away_team_id]).filter((id): id is string => !!id)
  );
  return pool.filter((t) => !placed.has(t.id));
}

/**
 * How many later fixtures would lose their teams (and results) if this
 * first-round slot changed hands. Mirrors ScheduleService::clearDownstream():
 * walk the same (round + 1, order / 2) edges and stop at the first slot nobody
 * ever advanced into.
 */
export function downstreamImpact(match: Match, bracket: Match[]): number {
  const maxRound = bracket.reduce((max, m) => Math.max(max, m.round), 0);
  let cleared = 0;
  let cursor = match;

  while (cursor.round < maxRound) {
    const parent = bracket.find(
      (m) => m.round === cursor.round + 1 && m.order === Math.floor(cursor.order / 2)
    );
    if (!parent) break;

    const fed = cursor.order % 2 === 0 ? parent.home_team_id : parent.away_team_id;
    if (!fed) break;

    cleared++;
    cursor = parent;
  }

  return cleared;
}

export const THIRD_PLACE_LABEL = "Perebutan Juara 3";

/** The play-off between the beaten semifinalists, not a round of the draw. */
export const isThirdPlace = (m: Match) => m.bracket === "third_place";

/**
 * What phase a fixture belongs to, in words a spectator recognises.
 *
 * The round *number* means nothing on its own — "Babak 2" is Semifinal in a
 * four-team draw and the Round of 16 in a thirty-two-team one. Only the number
 * of ties in that round says which, so the whole list has to be in hand.
 */
export function phaseLabel(m: Match, all: Match[], knockout = false): string {
  if (isThirdPlace(m)) return THIRD_PLACE_LABEL;
  if (m.group_name) return `Grup ${m.group_name}`;

  if (!knockout && m.stage !== "knockout") return `Pekan ${m.round}`;

  const inRound = all.filter(
    (x) => x.round === m.round && x.stage === m.stage && !isThirdPlace(x)
  ).length;

  return knockoutRoundLabel(inRound || 1);
}

/** Human label for a knockout round based on how many matches it holds. */
export function knockoutRoundLabel(matchesInRound: number): string {
  switch (matchesInRound) {
    case 1:
      return "Final";
    case 2:
      return "Semifinal";
    case 4:
      return "Perempat Final";
    case 8:
      return "16 Besar";
    case 16:
      return "32 Besar";
    default:
      return `${matchesInRound * 2} Besar`;
  }
}

/** Winning team id of a (finished) match, or null if undecided/bye-pending. */
export function matchWinnerId(m: Match): string | null {
  if (m.home_team_id && !m.away_team_id) return m.home_team_id; // walkover
  if (m.status === "finished" && m.home_score !== null && m.away_score !== null) {
    if (m.home_score > m.away_score) return m.home_team_id;
    if (m.away_score > m.home_score) return m.away_team_id;
    // Level: the shootout decided it.
    if (m.home_penalty !== null && m.away_penalty !== null) {
      if (m.home_penalty > m.away_penalty) return m.home_team_id;
      if (m.away_penalty > m.home_penalty) return m.away_team_id;
    }
  }
  return null;
}

/** True when the tie went to penalties. */
export const wentToPenalties = (m: Match) =>
  m.home_penalty !== null && m.away_penalty !== null;

const CREST_GRADIENTS = [
  "linear-gradient(135deg,#1E6FFF,#1558CC)",
  "linear-gradient(135deg,#059669,#047857)",
  "linear-gradient(135deg,#DC2626,#991B1B)",
  "linear-gradient(135deg,#D97706,#B45309)",
  "linear-gradient(135deg,#7C3AED,#5B21B6)",
  "linear-gradient(135deg,#0EA5E9,#0369A1)",
];

export function crestGradient(seed: string): string {
  let h = 0;
  for (let i = 0; i < seed.length; i++) h = (h * 31 + seed.charCodeAt(i)) >>> 0;
  return CREST_GRADIENTS[h % CREST_GRADIENTS.length];
}

/** Group matches by round number, ascending. */
export function groupByRound(matches: Match[]): [number, Match[]][] {
  const map = new Map<number, Match[]>();
  for (const m of matches) {
    const list = map.get(m.round) ?? [];
    list.push(m);
    map.set(m.round, list);
  }
  return [...map.entries()].sort((a, b) => a[0] - b[0]);
}

/**
 * Build labelled match sections for the schedule list. Double elimination is
 * split by bracket (Winners / Losers / Grand Final), hybrid by stage (group
 * matchdays, then the knockout rounds); other formats group by round.
 */
export function buildMatchSections(
  matches: Match[],
  knockout: boolean,
  doubleElim: boolean,
  hybrid = false
): [string, Match[]][] {
  if (hybrid) {
    const out: [string, Match[]][] = [];

    for (const [round, list] of groupByRound(matches.filter((m) => m.stage === "group"))) {
      const leg = list[0]?.leg ?? 1;
      out.push([leg > 1 ? `Fase Grup · Matchday ${round} (Leg 2)` : `Fase Grup · Matchday ${round}`, list]);
    }
    // The third-place tie shares the final's round; counting it would turn a
    // one-match final into a two-match "Semifinal".
    const ko = matches.filter((m) => m.stage === "knockout");
    for (const [, list] of groupByRound(ko.filter((m) => !isThirdPlace(m)))) {
      out.push([`Knockout · ${knockoutRoundLabel(list.length)}`, list]);
    }
    const thirdKo = ko.filter(isThirdPlace);
    if (thirdKo.length) out.push([`Knockout · ${THIRD_PLACE_LABEL}`, thirdKo]);

    // Fixtures added by hand carry no stage, so they belong to neither loop
    // above — without this they'd vanish from the list entirely.
    const loose = matches.filter((m) => m.stage !== "group" && m.stage !== "knockout");
    if (loose.length) out.push(["Pertandingan Tambahan", loose]);

    return out;
  }

  if (doubleElim) {
    const groups: [Match["bracket"], string][] = [
      ["winners", "Winners"],
      ["losers", "Losers"],
      ["grand_final", "Grand Final"],
    ];
    const out: [string, Match[]][] = [];
    for (const [bracket, label] of groups) {
      const ms = matches.filter((m) => m.bracket === bracket && m.status !== "cancelled");
      for (const [round, list] of groupByRound(ms)) {
        const heading =
          bracket === "grand_final"
            ? round === 2
              ? "Grand Final (Reset)"
              : "Grand Final"
            : `${label} · Babak ${round}`;
        out.push([heading, list]);
      }
    }
    // Same gap as hybrid: a hand-added fixture sits in no bracket.
    const loose = matches.filter(
      (m) => !groups.some(([bracket]) => bracket === m.bracket) && m.status !== "cancelled"
    );
    if (loose.length) out.push(["Pertandingan Tambahan", loose]);
    return out;
  }

  const third = matches.filter(isThirdPlace);
  const sections = groupByRound(matches.filter((m) => !isThirdPlace(m))).map(
    ([round, list]) => [knockout ? knockoutRoundLabel(list.length) : `Putaran ${round}`, list] as [string, Match[]]
  );

  if (third.length) sections.push([THIRD_PLACE_LABEL, third]);

  return sections;
}
