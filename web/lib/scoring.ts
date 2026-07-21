import type {
  EventCategory,
  Match,
  MatchRubber,
  ParticipantType,
  SportDef,
  SportEvent,
} from "@/types/api";

/**
 * Whether an event is scored per set (match score = sets won). The sport says
 * so itself now — see the `scoring` column behind the catalog — so there's no
 * list of sports to keep in sync here.
 */
export function isSetBased(event: Pick<SportEvent, "sport"> | null | undefined): boolean {
  return event?.sport?.scoring === "set";
}

/**
 * Whether a category's fixtures are ties played over several partai rather than
 * one scoreline. Derived on the server (it needs the sport); read, don't rebuild.
 */
export function usesRubbers(category: Pick<EventCategory, "uses_rubbers"> | null | undefined): boolean {
  return category?.uses_rubbers === true;
}

const PARTICIPANT_LABELS: Record<ParticipantType, string> = {
  single: "Tunggal",
  double: "Ganda",
  team: "Tim",
};

export function participantLabel(type: ParticipantType | null | undefined): string {
  return PARTICIPANT_LABELS[type ?? "team"];
}

/** The entrant shapes a sport can be run in, squad-only when it says nothing. */
export function participantModes(
  sport: Pick<SportDef, "participant_modes"> | null | undefined
): ParticipantType[] {
  return sport?.participant_modes?.length ? sport.participant_modes : ["team"];
}

/**
 * What the standings table's for/against columns actually count. A squad tie
 * scores partai, not goals — same numbers, different word.
 */
export function scoreColumnLabels(category: Pick<EventCategory, "uses_rubbers"> | null | undefined): {
  for: string;
  against: string;
  diff: string;
} {
  return usesRubbers(category)
    ? { for: "PM", against: "PK", diff: "SP" }
    : { for: "GM", against: "GK", diff: "SG" };
}

/** "Dimas / Ammar", falling back to a placeholder while the lineup is unset. */
export function rubberLineup(names: string[] | null | undefined): string {
  return names?.length ? names.join(" / ") : "—";
}

/** "21-16, 22-20" — the set detail of one partai. */
export function setsText(sets: { home: number; away: number }[] | null | undefined): string {
  return (sets ?? []).map((s) => `${s.home}-${s.away}`).join(", ");
}

/** Display text for a match result: a main score plus optional set detail. */
export function matchScoreText(m: Match): { main: string; detail?: string } {
  if (m.home_score === null || m.away_score === null) {
    return { main: "vs" };
  }

  const main = `${m.home_score} – ${m.away_score}`;

  // A tie's detail is its partai, not `sets` — which is null by design here.
  if (m.rubbers?.length) {
    const played = m.rubbers.filter((r: MatchRubber) => r.home_score !== null);
    return played.length
      ? { main, detail: played.map((r) => `${r.home_score}-${r.away_score}`).join(", ") }
      : { main };
  }

  if (m.sets && m.sets.length > 0) {
    return { main, detail: setsText(m.sets) };
  }
  return { main };
}
