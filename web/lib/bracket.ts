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
    for (const [, list] of groupByRound(matches.filter((m) => m.stage === "knockout"))) {
      out.push([`Knockout · ${knockoutRoundLabel(list.length)}`, list]);
    }

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
    return out;
  }

  return groupByRound(matches).map(
    ([round, list]) => [knockout ? knockoutRoundLabel(list.length) : `Putaran ${round}`, list] as [string, Match[]]
  );
}
