import type { Match, SportEvent } from "@/types/api";

/**
 * Whether an event is scored per set (match score = sets won). The sport says
 * so itself now — see the `scoring` column behind the catalog — so there's no
 * list of sports to keep in sync here.
 */
export function isSetBased(event: Pick<SportEvent, "sport"> | null | undefined): boolean {
  return event?.sport?.scoring === "set";
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
