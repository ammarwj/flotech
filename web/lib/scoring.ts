import type {
  BanReason,
  DisciplineRules,
  EventCategory,
  Match,
  MatchRubber,
  ParticipantType,
  SportDef,
  SportStatDef,
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
 * The racket family — badminton, tenis, tenis meja, padel — recognised by the
 * one thing that sets it apart: a category can be entered by a single player.
 *
 * Keyed on the entrant shapes rather than a list of slugs, so a racket sport an
 * admin adds later behaves the same without touching this file. The same test
 * appears in `standingsContextOf` below. Volleyball is also scored per set but
 * is squad-only, so it is *not* one of these.
 */
function isRacketSport(
  sport: Pick<SportDef, "participant_modes"> | null | undefined
): boolean {
  return participantModes(sport).includes("single");
}

/**
 * Whether a roster of this sport carries shirt numbers and positions.
 *
 * A racket sport is played by people, not squads: nobody wears a number in
 * badminton, and "Tunggal"/"Ganda" is the kategori, not a place on the field. So
 * the entry form drops both and keeps the two things such a roster actually has
 * — name and photo.
 */
export function usesSquadFields(
  sport: Pick<SportDef, "participant_modes"> | null | undefined
): boolean {
  return !isRacketSport(sport);
}

/**
 * Whether the public pages show player statistics for this sport.
 *
 * Racket sports don't: a squad tie's stats have no input path at all (the score
 * is filled in per partai, and `player_match_stats` knows nothing about one), so
 * the tab is permanently empty — and showing it for tunggal/ganda alone would
 * give the same sport two shapes of public page. Shares `isRacketSport` with
 * `usesSquadFields` by coincidence of the same test, not the same reason: these
 * two can drift apart without the other having to follow.
 */
export function showsPlayerStats(
  sport: Pick<SportDef, "participant_modes"> | null | undefined
): boolean {
  return !isRacketSport(sport);
}

/**
 * The card columns of a sport, found by what they mean rather than what they
 * are called. An admin may rename `yellow_cards` from /admin/sports; the role
 * is the part that is load-bearing.
 */
export function disciplineStatDefs(
  sport: Pick<SportDef, "stats"> | null | undefined
): { yellow: SportStatDef | null; red: SportStatDef | null } {
  const stats = sport?.stats ?? [];

  return {
    yellow: stats.find((s) => s.role === "yellow") ?? null,
    red: stats.find((s) => s.role === "red") ?? null,
  };
}

/**
 * The only shape `tracksDiscipline` needs. Kept this narrow so the admin sports
 * page — whose rows spell the key `stat_key`, not `key` — can ask the same
 * question as the organizer pages instead of writing its own copy of the rule.
 */
type CardBearing = { stats: readonly { role: SportStatDef["role"] }[] };

/**
 * Whether this sport books players at all, and so whether card accumulation and
 * playing bans mean anything for it.
 *
 * Deliberately *not* `showsPlayerStats`, even though both are about stats.
 * Volleyball and basketball field squads and keep a leaderboard, yet neither has
 * a card column — a test borrowed from there would switch this feature on for
 * two sports where it can never fire, and hand the organizer an endpoint that is
 * empty forever. Asking the stats directly also means a sport an admin gives
 * cards to tomorrow gets the feature without a deploy.
 */
export function tracksDiscipline(sport: CardBearing | null | undefined): boolean {
  return (sport?.stats ?? []).some((s) => s.role === "yellow" || s.role === "red");
}

/**
 * Why a player is sitting out, in words the organizer uses — "kartu merah",
 * "2 kartu kuning (dikeluarkan)", "akumulasi 3 kartu kuning". The card's name
 * comes from the sport's own label, and the numbers from the rules in force for
 * this category.
 */
export function banReasonLabel(
  reason: BanReason,
  sport: Pick<SportDef, "stats"> | null | undefined,
  rules:
    | Pick<DisciplineRules, "yellow_threshold" | "yellows_per_expulsion">
    | null
    | undefined
): string {
  const { yellow, red } = disciplineStatDefs(sport);

  if (reason === "red_card") {
    return (red?.label ?? "Kartu merah").toLowerCase();
  }

  const label = (yellow?.label ?? "Kartu kuning").toLowerCase();

  // Two cautions in one match, not a tally that built up over the tournament —
  // spelling out "dikeluarkan" is what keeps the two apart on the card.
  if (reason === "second_yellow") {
    return `${rules?.yellows_per_expulsion ?? 2} ${label} (dikeluarkan)`;
  }

  const threshold = rules?.yellow_threshold;

  return threshold ? `akumulasi ${threshold} ${label}` : `akumulasi ${label}`;
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
/** Sits right after LOST, not at the far right: it is the number people scan for. */
const POINTS: StandingColumn = { key: "points", short: "Poin", legend: "poin", cell: (s) => String(s.points) };

/**
 * The columns after "Tim", in order — Poin included.
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
      POINTS,
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
      POINTS,
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
    POINTS,
    { key: "goals_for", short: "GM", legend: "gol masuk", cell: (s) => String(s.goals_for) },
    { key: "goals_against", short: "GK", legend: "gol kemasukan", cell: (s) => String(s.goals_against) },
    { key: "goal_diff", short: "SG", legend: "selisih gol", cell: (s) => signed(s.goal_diff) },
  ];
}

/**
 * The legend under a standings table, spelling out the headers above it —
 * except Poin, whose header is already the whole word.
 */
export function standingsLegend(context: StandingsContext): string {
  return `${standingsColumns(context)
    .filter((c) => c.key !== "points")
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
