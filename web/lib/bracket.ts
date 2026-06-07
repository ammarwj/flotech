import type { Match } from "@/types/api";

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
  }
  return null;
}

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
