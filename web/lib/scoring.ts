import type { Match, SportType } from "@/types/api";

/** Sports scored per set (match score = number of sets won). */
const SET_BASED: SportType[] = ["volleyball", "badminton", "padel"];

export function isSetBased(sport: SportType): boolean {
  return SET_BASED.includes(sport);
}

/** Display text for a match result: a main score plus optional set detail. */
export function matchScoreText(m: Match): { main: string; detail?: string } {
  if (m.home_score === null || m.away_score === null) {
    return { main: "vs" };
  }

  const main = `${m.home_score} – ${m.away_score}`;
  if (m.sets && m.sets.length > 0) {
    return { main, detail: m.sets.map((s) => `${s.home}-${s.away}`).join(", ") };
  }
  return { main };
}
