import type {
  EventCategory,
  Match,
  MatchRubber,
  ParticipantType,
  SportDef,
  SportEvent,
  Standing,
  StandingsContext,
} from "@/types/api";

/**
 * Whether an event is scored per set (match score = sets won). The sport says
 * so itself now — see the `scoring` column behind the catalog — so there's no
 * list of sports to keep in sync here.
 */
export function isSetBased(event: Pick<SportEvent, "sport"> | null | undefined): boolean {
  return event?.sport?.scoring === "set";
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
 * The standings shape of a category. Prefer the server's answer — it needs the
 * sport, which lives on the event — and fall back to deriving it, which the
 * event form has to do for a category that isn't saved yet.
 */
export function standingsContextOf(
  category:
    | Partial<
        Pick<
          EventCategory,
          "standings_context" | "uses_rubbers" | "participant_type" | "rubber_format"
        >
      >
    | null
    | undefined,
  sport?: Pick<SportDef, "scoring" | "participant_modes"> | null
): StandingsContext {
  if (category?.standings_context) return category.standings_context;

  const rubbers =
    category?.uses_rubbers ??
    ((category?.participant_type ?? "team") === "team" &&
      participantModes(sport).includes("single") &&
      !!category?.rubber_format?.length);

  if (rubbers) return "rubber";
  return sport?.scoring === "set" ? "set" : "goal";
}

/** One statistic column of a standings table. */
export type StandingColumn = {
  key: string;
  /** Header text — abbreviated, so the table fits a phone. */
  short: string;
  /** How the legend under the table spells it out. */
  legend: string;
  cell: (s: Standing) => string;
};

const signed = (n: number) => (n > 0 ? `+${n}` : String(n));
/** "12-8" — the pair badminton reads as one number. */
const pair = (forCount: number, against: number) => `${forCount}-${against}`;

const PLAYED: StandingColumn = { key: "played", short: "M", legend: "main", cell: (s) => String(s.played) };
const WON: StandingColumn = { key: "won", short: "M", legend: "menang", cell: (s) => String(s.won) };
const DRAWN: StandingColumn = { key: "drawn", short: "S", legend: "seri", cell: (s) => String(s.drawn) };
const LOST: StandingColumn = { key: "lost", short: "K", legend: "kalah", cell: (s) => String(s.lost) };

/**
 * The columns between "Tim" and "Poin", in order.
 *
 * Every branch on sport lives here rather than in the table, because the words
 * follow the numbers: `goals_for` is gol in football, game menang in badminton
 * tunggal, and partai menang in a squad tie. A hardcoded "GM" reads as a bug in
 * two of those three.
 *
 * Football keeps its familiar one-number-per-column layout; the set-based
 * shapes pair for/against into a single cell ("12-8"), which is how a badminton
 * table is actually printed.
 */
export function standingsColumns(context: StandingsContext): StandingColumn[] {
  if (context === "rubber") {
    return [
      PLAYED,
      WON,
      // A tie *can* finish level — 1-1 over two partai — so seri stays.
      DRAWN,
      LOST,
      { key: "rubbers", short: "Partai", legend: "partai menang-kalah", cell: (s) => pair(s.goals_for, s.goals_against) },
      { key: "games", short: "Game", legend: "game menang-kalah", cell: (s) => pair(s.sets_for, s.sets_against) },
      { key: "score", short: "Skor", legend: "poin menang-kalah", cell: (s) => pair(s.points_for, s.points_against) },
    ];
  }

  if (context === "set") {
    return [
      PLAYED,
      WON,
      // No seri column: a match decided on games cannot end level.
      LOST,
      { key: "games", short: "Game", legend: "game menang-kalah", cell: (s) => pair(s.goals_for, s.goals_against) },
      { key: "score", short: "Skor", legend: "poin menang-kalah", cell: (s) => pair(s.points_for, s.points_against) },
      { key: "game_diff", short: "±G", legend: "selisih game", cell: (s) => signed(s.goal_diff) },
      { key: "score_diff", short: "±S", legend: "selisih poin", cell: (s) => signed(s.points_diff) },
    ];
  }

  return [
    PLAYED,
    WON,
    DRAWN,
    LOST,
    { key: "goals_for", short: "GM", legend: "gol masuk", cell: (s) => String(s.goals_for) },
    { key: "goals_against", short: "GK", legend: "gol kemasukan", cell: (s) => String(s.goals_against) },
    { key: "goal_diff", short: "SG", legend: "selisih gol", cell: (s) => signed(s.goal_diff) },
  ];
}

/** The legend under a standings table, spelling out the headers above it. */
export function standingsLegend(context: StandingsContext): string {
  return `${standingsColumns(context)
    .map((c) => `${c.short}: ${c.legend}`)
    .join(" · ")}.`;
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
