export interface ApiEnvelope<T> {
  success: boolean;
  message: string;
  data: T;
}

export interface AuthUser {
  id: string;
  full_name: string;
  email: string;
  phone: string | null;
  avatar_url: string | null;
  role: "super_admin" | "user";
  /** Which dashboard the next login opens in. */
  default_mode: "organizer" | "participant";
  is_verified: boolean;
  email_verified_at: string | null;
}

export interface AuthTokenResponse {
  access_token: string;
  token_type: string;
  expires_in: number;
  user: AuthUser;
}

export interface Plan {
  id: string;
  name: string;
  slug: string;
  description: string | null;
  /** One-time, per event. There is no billing cycle. */
  price: number;
  is_active: boolean;
  is_public: boolean;
  sort_order: number;
  features?: Record<string, string>;
  feature_details?: PlanFeatureDetail[];
}

/** One feature definition resolved against a plan; `value` is null when the plan lacks it. */
export interface PlanFeatureDetail {
  key: string;
  label: string;
  group: string | null;
  type: "boolean" | "numeric" | "text";
  description: string | null;
  value: string | null;
  included: boolean;
}

export interface FeatureDefinition {
  id: string;
  feature_key: string;
  feature_label: string;
  feature_group: string | null;
  feature_type: "boolean" | "numeric" | "text";
  description: string | null;
  sort_order: number;
}

/** Avatar gradients are looked up in `lib/landing.ts`; the API only stores the key. */
export type AvatarPreset = "brand" | "purple" | "pink" | "success" | "amber" | "blue";

export interface Testimonial {
  id: string;
  quote: string;
  name: string;
  role: string;
  initials: string;
  avatar_preset: AvatarPreset;
  rating: number;
  is_active: boolean;
  sort_order: number;
}

export interface Faq {
  id: string;
  question: string;
  answer: string;
  is_active: boolean;
  sort_order: number;
}

export type SocialPlatform = "instagram" | "youtube" | "x" | "tiktok" | "facebook";

/** Profile URLs per platform. Unset platforms are `null` (or absent, publicly). */
export type SocialLinks = Partial<Record<SocialPlatform, string | null>>;

/**
 * How to reach flo-event itself — rendered in the footer, edited by super_admin
 * at /admin/site-settings. One record, so no id and nothing to list.
 *
 * The public endpoint omits the platforms nobody filled in; the admin one keeps
 * all five (as `null`) so the settings form binds to a stable set of inputs.
 */
export interface SiteSettings {
  contact_email: string | null;
  contact_phone: string | null;
  /** CTA of the Professional plan card. Empty = fall back to `contact_email`. */
  sales_email: string | null;
  social_links: SocialLinks;
  /**
   * The platform's own account — where an organizer transfers for a plan while
   * a super admin has the gateway switched off. Admin endpoint only; the public
   * footer shape omits these entirely.
   */
  bank_name?: string | null;
  bank_code?: string | null;
  account_number?: string | null;
  account_holder?: string | null;
}

export interface Organization {
  id: string;
  name: string;
  slug: string;
  logo_url: string | null;
  banner_url: string | null;
  description: string | null;
  contact_email: string | null;
  contact_phone: string | null;
  social_links: SocialLinks;
  custom_domain: string | null;
  owner_id: string;
  /**
   * How the signed-in user relates to this org. An `operator` records results
   * but cannot sign them off, so the dashboard hides ratifying controls.
   */
  my_role: "owner" | "admin" | "operator" | null;
  /**
   * Platform-wide, not a property of this org: false means a super admin has
   * switched Midtrans off and all sales run on manual bank transfer.
   */
  payment_gateway_enabled: boolean;
  /** Whether a primary payout account exists — the manual-transfer destination. */
  has_bank_account: boolean;
  /**
   * A manual plan payment sitting in the super admin's queue. Derived, and it
   * rides here rather than on its own endpoint because the banner reading it
   * renders for `operator` members too, who can't call /subscriptions.
   */
  plan_payment_awaiting_verification: boolean;
  /** Paid plans not yet spent on an event — drives the "you're holding one" banner. */
  unconsumed_plan_orders_count: number;
}

export type PlanOrderStatus = "past_due" | "paid" | "cancelled";

/**
 * The slim plan an event carries — enough to gate on, without the thirteen
 * `feature_details` entries the events list would repeat per row. Mirrors
 * PlanSummaryResource.
 */
export interface PlanSummary {
  id: string;
  name: string;
  slug: string;
  features?: Record<string, string>;
}

/**
 * One purchase of one plan, for one event.
 *
 * Extends ManualPaymentFields (declared further down) for the same reason ticket
 * orders and teams do: while the payment gateway is off, an organizer pays for a
 * plan by bank transfer and uploads a receipt. The difference is who verifies it
 * — a super admin, because the money lands in the platform's own account.
 *
 * A paid order with `event_id: null` is a *credit*: it entitles nothing until an
 * event spends it. `unconsumedOrders()` in lib/plan.ts is the only place that
 * pair should be read.
 */
export interface EventPlanOrder extends ManualPaymentFields {
  id: string;
  organization_id: string;
  plan_id: string | null;
  /** Issued at checkout — every order has one, paid or not. */
  invoice_number: string | null;
  /** Issued on payment — only paid orders have one. */
  receipt_number: string | null;
  amount: number;
  status: PlanOrderStatus;
  /** The event this credit was spent on; null while it is still unspent. */
  event_id: string | null;
  consumed_at: string | null;
  event?: { id: string; name: string };
  /** Set on a top-up bill: the order it upgrades. Never itself a credit. */
  upgrade_of_id: string | null;
  /** True once a paid upgrade has taken this order's place — also not a credit. */
  superseded: boolean;
  midtrans_order_id: string | null;
  payment_type: string | null;
  paid_at: string | null;
  plan?: Plan;
  /** Where to transfer. Only on an unpaid manual bill. */
  bank_account?: PublicBankAccount | null;
  /** Only in the super admin's verification queue, which spans organizations. */
  organization?: { id: string; name: string };
}

/**
 * Same shape as every other "start a payment" response — see PaymentStart. Read
 * it through `checkoutOutcome()` in lib/checkout.ts rather than branching on
 * `redirect_url` here: a null one means "manual", "activated" or "the gateway
 * call failed", and only the subscription's own status tells them apart.
 */
/** A plan this order may move up to, and what the move costs. */
export interface PlanUpgradeOption {
  plan: Plan;
  /** Target price minus what was already paid — never the full price. */
  price_difference: number;
}

export interface CheckoutResult extends PaymentStart {
  plan_order: EventPlanOrder;
}

// The vocabulary below is admin-managed data (see /catalog), so these are open
// string keys, not closed unions — a new sport or format appears without a
// deploy. Use useCatalog() to turn a key into a label/colour.
export type SportType = string;
export type TournamentFormat = string;
export type KnockoutRound = string;
export type Tiebreaker = string;
export type DrawMethod = string;

/** The engines the backend can actually run a format on. */
export type FormatEngine = "league" | "knockout_single" | "knockout_double" | "hybrid";

/**
 * What shape a category's table has — and so which columns it shows, which
 * tiebreakers it offers, and what its points default to.
 *
 * `goal` counts gol, `set` counts game menang with the points behind them
 * (badminton tunggal/ganda, voli), `rubber` counts partai with the games and
 * points behind those (badminton beregu).
 */
export type StandingsContext = "goal" | "set" | "rubber";

export interface SportStatDef {
  key: string;
  label: string;
  short: string;
  /**
   * What the stat means to the engine. 'goal' cross-checks the score, 'assist'
   * can't outnumber the goals, and 'yellow'/'red' are what the suspension
   * engine reads — it may not look for a stat key by name, since an admin can
   * rename keys from /admin/sports.
   */
  role: "goal" | "assist" | "yellow" | "red" | null;
  /** Weight in the fair-play tiebreaker (yellow 1, red 3). 0 = not misconduct. */
  fair_play_weight: number;
}

/**
 * When a card turns into a ban. Every field is optional at rest: a sport or an
 * event that never set one inherits the layer below it.
 */
export interface DisciplineRuleValues {
  /** Yellows that accumulate into a ban. */
  yellow_threshold?: number;
  yellow_ban_matches?: number;
  red_ban_matches?: number;
  /** Yellows inside one match that amount to a sending-off. 0 = rule off. */
  yellows_per_expulsion?: number;
  expulsion_ban_matches?: number;
  /** Group-stage yellows wiped at the bracket. Hybrid categories only. */
  reset_yellow_on_knockout?: boolean;
}

/** A position a player of this sport can hold. The key is what a roster stores. */
export interface SportPositionDef {
  key: string;
  label: string;
}

/** A role a team official of this sport can hold (Pelatih Kepala, Manajer Tim…). */
export interface SportOfficialRoleDef {
  key: string;
  label: string;
}

export interface SportDef {
  slug: string;
  name: string;
  color: string;
  icon: string | null;
  scoring: "goal" | "set";
  /** Entrant shapes this sport can be run with. Squad-only for most sports. */
  participant_modes: ParticipantType[];
  default_match_minutes: number;
  /** Card thresholds this sport's events inherit; {} = platform defaults. */
  discipline_config: DisciplineRuleValues;
  stats: SportStatDef[];
  positions: SportPositionDef[];
  official_roles: SportOfficialRoleDef[];
}

/** A reference option: a format, tiebreaker, draw method, round, sponsor tier. */
export interface CatalogOption {
  key: string;
  label: string;
  description: string | null;
  /** Binds the option to code: {engine} / {comparator} / {strategy} / {size}. */
  meta: Record<string, unknown>;
}

export interface Catalog {
  sports: SportDef[];
  tournament_formats: CatalogOption[];
  tiebreakers: CatalogOption[];
  draw_methods: CatalogOption[];
  knockout_rounds: CatalogOption[];
  sponsor_tiers: CatalogOption[];
}

/**
 * Format configuration of an event (`bracket_config`). Only the hybrid format
 * uses all of it; a league reads the points and tiebreakers.
 */
export interface BracketConfig {
  groups?: number;
  teams_per_group?: number;
  home_away?: boolean;
  legs?: number;
  points?: { win?: number; draw?: number; lose?: number };
  qualification?: {
    top_per_group?: number;
    best_runners_up?: number;
    best_thirds?: number;
  };
  /** Entry round of the knockout stage; omitted = sized from the qualifiers. */
  knockout_start?: KnockoutRound | null;
  /** Play an extra tie between the beaten semifinalists. */
  third_place?: boolean;
  draw_method?: DrawMethod;
  tiebreakers?: Tiebreaker[];
}
export type EventStatus =
  | "draft"
  | "open"
  | "registration_closed"
  | "ongoing"
  | "finished"
  | "cancelled";
export type TeamStatus = "pending" | "approved" | "rejected" | "disqualified" | "withdrawn";

export type MatchStatus = "scheduled" | "ongoing" | "finished" | "cancelled";

export interface MatchTeamRef {
  id: string;
  name: string;
  logo_url: string | null;
}

/**
 * Which side of a double-elimination draw a fixture sits on, plus the
 * third-place play-off — which reuses this column to mark itself apart from the
 * final it shares a round with.
 */
export type BracketSide = "winners" | "losers" | "grand_final" | "third_place";

/**
 * Stage of a hybrid event; null for the single-stage formats.
 *
 * `playoff` belongs to neither stage and to every format that has a table: it
 * is a decider, played only to separate two teams no criterion could, and the
 * stage is exactly what keeps its result out of the standings it settles.
 */
export type MatchStage = "group" | "knockout" | "playoff" | null;

export interface Match {
  id: string;
  stage: MatchStage;
  round: number;
  group_name: string | null;
  bracket: BracketSide | null;
  order: number;
  /** Leg of a home & away tie (1 or 2). */
  leg: number;
  home_team: MatchTeamRef | null;
  away_team: MatchTeamRef | null;
  home_team_id: string | null;
  away_team_id: string | null;
  home_score: number | null;
  away_score: number | null;
  /** Penalty shootout — only set on a knockout tie that ended level. */
  home_penalty: number | null;
  away_penalty: number | null;
  /** Per-set scores for set-based sports; null for goal-based sports. */
  sets: { home: number; away: number }[] | null;
  /**
   * The partai a squad tie is played over. Present only for a category that
   * uses them, in which case home/away_score above is how many each side won
   * and `sets` is null — a tie has no single run of sets.
   */
  rubbers?: MatchRubber[];
  status: MatchStatus;
  /** True once the result is confirmed (counts toward standings/bracket). */
  confirmed: boolean;
  scheduled_at: string | null;
  venue: string | null;
}

/** What one entrant of a category is. */
export type ParticipantType = "single" | "double" | "team";

/** A row of a category's partai template. */
export interface RubberFormatRow {
  label: string;
  type: "single" | "double";
}

/**
 * One partai of a squad tie: "Ganda Putra — Dimas/Ammar vs Ucang/Devan,
 * 21-16 / 22-20". home/away_score are sets won in this partai.
 */
export interface MatchRubber {
  id: string;
  match_id: string;
  order: number;
  label: string;
  type: "single" | "double";
  home_player_ids: string[];
  away_player_ids: string[];
  /** Lineup names, when the rosters were loaded alongside. */
  home_players: string[] | null;
  away_players: string[] | null;
  sets: { home: number; away: number }[] | null;
  home_score: number | null;
  away_score: number | null;
  status: "scheduled" | "finished" | "walkover";
}

/** A place in the knockout bracket, e.g. "Juara Grup A" — and who holds it now. */
export interface KnockoutSlot {
  /**
   * Stable identity of the place itself: "A1", "B2", "BR1". This is what a
   * saved plan pairs up, so it survives the standings moving underneath it.
   */
  key: string;
  label: string;
  group: string | null;
  place: number;
  /** Current occupant from the live group table; null until there are results. */
  team: MatchTeamRef | null;
}

/** The bracket as planned, before (or while) the group stage plays out. */
export interface KnockoutPlan {
  bracket_size: number;
  qualifiers: number;
  byes: number;
  /**
   * Group fixtures that exist at all. Zero `pending` is ambiguous on its own —
   * all played, or none scheduled — and only the second blocks the bracket.
   */
  group_matches_total: number;
  group_matches_pending: number;
  /** "manual" once the organizer saved their own draw; "auto" = seeded from the standings. */
  source: "manual" | "auto";
  /**
   * A saved draw that outlived the qualification rules it was made against —
   * it names slots that no longer exist. Reported, never silently repaired:
   * only the organizer knows where those pairings should go now.
   */
  stale: boolean;
  /** Qualifier slots the saved draw places nowhere; blocks activation. */
  unplaced_slots: KnockoutSlot[];
  /** Every qualifier slot, in seed order — the pool the plan editor draws from. */
  slots: KnockoutSlot[];
  ties: {
    order: number;
    home: KnockoutSlot | null;
    away: KnockoutSlot | null;
    /** Kickoff/court booked for this tie before its teams were known. */
    scheduled_at: string | null;
    venue: string | null;
  }[];
}

export interface Standing {
  rank: number;
  team: MatchTeamRef;
  /** Group the team was drawn into (hybrid); null for a single table. */
  group_name: string | null;
  played: number;
  won: number;
  drawn: number;
  lost: number;
  /** The match score, whatever the sport calls it: gol, game, or partai. */
  goals_for: number;
  goals_against: number;
  goal_diff: number;
  /** Games behind a squad tie. Zero elsewhere — see StandingsContext. */
  sets_for: number;
  sets_against: number;
  set_diff: number;
  /** Raw points across every set played. Zero for a goal sport. */
  points_for: number;
  points_against: number;
  points_diff: number;
  points: number;
  /** Disciplinary points: 1 per yellow, 3 per red. Lower is better. */
  fair_play: number;
  /**
   * No criterion could separate this row from its neighbour, so what put it
   * where it is was the lot — the two are owed a decider. Only ever true when
   * the category still ranks on one, since otherwise playing it changes nothing.
   */
  needs_decider: boolean;
}

/** A stat column of a sport, as the API hands it to the editors. */
export interface StatColumn {
  key: string;
  label: string;
  short: string;
  /** What the stat means: 'goal' is the scoreline, 'assist' can't exceed it. */
  role?: "goal" | "assist" | null;
  fair_play_weight?: number;
}

export interface LeaderboardRow {
  rank: number;
  player_id: string;
  player_name: string;
  jersey_number: string | null;
  team_id: string;
  team_name: string;
  stats: Record<string, number>;
}

export interface Leaderboard {
  columns: StatColumn[];
  primary: string;
  rows: LeaderboardRow[];
}

export interface RosterPlayer {
  id: string;
  full_name: string;
  jersey_number: string | null;
}

export interface MatchRoster {
  id: string;
  name: string;
  players: RosterPlayer[];
}

export interface MatchStatsData {
  columns: StatColumn[];
  home_team: MatchRoster | null;
  away_team: MatchRoster | null;
  /** player_id => { stat_key => value } */
  stats: Record<string, Record<string, number>>;
}

/**
 * The public read-only view of the same data. Deliberately not `MatchStatsData`:
 * nothing here is editable, so it carries no roster — only the players who
 * recorded something, with their tally attached.
 */
export interface PublicMatchStatPlayer {
  id: string;
  full_name: string;
  jersey_number: string | null;
  stats: Record<string, number>;
}

export interface PublicMatchStatTeam {
  id: string;
  name: string;
  players: PublicMatchStatPlayer[];
}

export interface PublicMatchStats {
  columns: StatColumn[];
  home_team: PublicMatchStatTeam | null;
  away_team: PublicMatchStatTeam | null;
}

/**
 * One competition inside an event (U17, U19, Woman, …). Format, bracket config,
 * fee and team cap live here — an event may run several at once, each different.
 */
export interface EventCategory {
  id: string;
  event_id: string;
  name: string;
  slug: string;
  /** What one entrant is: a lone player, a pair, or a squad. */
  participant_type: ParticipantType;
  /** Template of partai a squad tie is played over; null unless it uses them. */
  rubber_format: RubberFormatRow[] | null;
  /** Derived server-side — needs the sport, which lives on the event. */
  uses_rubbers: boolean;
  /** Which shape the table takes. Derived server-side for the same reason. */
  standings_context: StandingsContext;
  /** Players an entrant has: 1 tunggal, 2 ganda, null for a squad. */
  roster_size: number | null;
  tournament_format: TournamentFormat;
  /** The engine the format runs on — branch on this, not the format key. */
  engine: FormatEngine | null;
  registration_fee: number;
  max_teams: number | null;
  bracket_config: BracketConfig | null;
  sort_order: number;
  teams_count?: number;
}

export interface SportEvent {
  id: string;
  organization_id: string;
  /**
   * The plan this event runs on. `plan_id` is always present so the client can
   * tell "no plan" from "not loaded"; `plan` only when eager-loaded. Every
   * entitlement the dashboard gates on is read from here — see lib/plan.ts.
   */
  plan_id: string | null;
  plan?: PlanSummary;
  name: string;
  slug: string;
  sport_type: SportType;
  /** The sport itself, embedded so the UI needn't look it up. */
  sport: SportDef | null;
  status: EventStatus;
  /**
   * Statuses this event may move to next, straight from the backend's
   * transition table. Empty = terminal (finished or cancelled).
   */
  next_statuses: EventStatus[];
  start_date: string | null;
  end_date: string | null;
  /** The venue's zone. Kickoff times are UTC instants; this is what they mean. */
  timezone: string;
  registration_open: string | null;
  registration_close: string | null;
  location_name: string | null;
  location_address: string | null;
  /** Named courts/pitches for scheduling; [] when the organizer set none. */
  courts: string[];
  description: string | null;
  banner_url: string | null;
  /** Competition rules the organizer set, namespaced. {} when never configured. */
  rules_config: EventRulesConfig;
  /** The competitions inside this event; each carries its own format & fee. */
  categories: EventCategory[];
  teams_count?: number;
}

/**
 * `events.rules_config`, one column holding several independent rulebooks. The
 * update endpoint merges per namespace, so a form that only knows about one of
 * them can't wipe the rest.
 */
export interface EventRulesConfig {
  discipline?: DisciplineRuleValues;
}

/**
 * Why a player is barred from a fixture. `second_yellow` is a sending-off for
 * two cautions in one match — a different fact from the accumulation ban, which
 * builds up across the tournament.
 */
export type BanReason = "red_card" | "second_yellow" | "yellow_accumulation";

/**
 * Whether this ban is still to be sat out, or already was.
 *
 * `served` exists so the fixture that discharged a ban can still be read after
 * it has been played — without it the feature goes blank the moment it works,
 * and "nobody was ever banned here" looks identical on screen to "somebody was,
 * and served it right here".
 */
export type BanStatus = "upcoming" | "served";

/** One player kept out of one fixture. */
export interface DisciplineBan {
  player_id: string;
  player_name: string;
  jersey_number: string | null;
  team_id: string;
  team_name: string;
  reason: BanReason;
  /** Fixtures still owed, counting this one. Same meaning under both statuses. */
  bans_remaining: number;
  status: BanStatus;
}

/** A player's running card tally across the category. */
export interface DisciplinePlayer {
  player_id: string;
  player_name: string;
  jersey_number: string | null;
  team_id: string;
  team_name: string;
  yellow_total: number;
  red_total: number;
  /** Yellows since the last ban was issued — the count that resets. */
  yellow_running: number;
  bans_issued: number;
  bans_served: number;
  bans_remaining: number;
}

/**
 * Card accumulation for one category, derived server-side on every read.
 *
 * `enabled` is false for a sport with no card stat at all (badminton, volleyball
 * — see `tracksDiscipline`), and everything else is then empty.
 */
export interface Discipline {
  enabled: boolean;
  rules: DisciplineRules | null;
  players: DisciplinePlayer[];
  /**
   * Fixture id => the bans touching it. Includes fixtures already played: see
   * `BanStatus`.
   */
  matches: Record<string, DisciplineBan[]>;
}

/** The rules as resolved for a category: sport defaults under event overrides. */
export interface DisciplineRules extends Required<DisciplineRuleValues> {
  /** The sport's own stat keys, so the UI can name a card from its label. */
  yellow_stat_key: string | null;
  red_stat_key: string | null;
}

/** A photo in one of an event's albums. */
export interface EventPhoto {
  id: string;
  /** Album name; null = the event's default album. */
  album: string | null;
  photo_url: string;
  caption: string | null;
  sort_order?: number;
}

export type SponsorTier = string;

export interface EventSponsor {
  id: string;
  name: string;
  logo_url: string;
  website_url: string | null;
  tier: SponsorTier;
  sort_order?: number;
}

export interface Player {
  id?: string;
  full_name: string;
  jersey_number?: string | null;
  position?: string | null;
  /** Optional profile photo (R2/local URL). */
  photo_url?: string | null;
}

/**
 * Someone on a team's bench — pelatih, manajer, ofisial. Not a Player: they
 * never appear in a lineup, a leaderboard, or a roster-size rule.
 */
export interface TeamOfficial {
  id?: string;
  full_name: string;
  /** A key from the sport's official_roles. Null when the sport defines none. */
  role?: string | null;
  photo_url?: string | null;
}

export interface TeamDocument {
  id?: string;
  document_type?: string | null;
  file_name?: string | null;
  file_url: string;
}

export type PaymentStatus = "unpaid" | "paid";

export interface Team extends ManualPaymentFields {
  id: string;
  event_id: string;
  /** The competition category this team is entered in. */
  category_id: string;
  name: string;
  logo_url: string | null;
  contact_name: string | null;
  contact_phone: string | null;
  status: TeamStatus;
  group_name: string | null;
  /** Seeding pot used by a pot-based group draw. */
  seed_pot: number | null;
  registered_at: string | null;
  approved_at: string | null;
  payment_status: PaymentStatus;
  payment_amount: number;
  platform_fee: number;
  paid_at: string | null;
  midtrans_token: string | null;
  players?: Player[];
  officials?: TeamOfficial[];
  documents?: TeamDocument[];
  event?: SportEvent;
  category?: EventCategory;
}

/**
 * How a payment is collected. `manual` means a super admin has the gateway
 * switched off, so the buyer transfers to the organizer's own bank account and
 * uploads proof — the platform never holds that money and takes no fee.
 */
export type PaymentMethod = "gateway" | "manual";

/** Where a buyer must transfer for a manual payment. */
export interface PublicBankAccount {
  bank_name: string;
  bank_code: string | null;
  account_number: string;
  account_holder: string;
}

/** Columns shared by anything payable — a ticket order or a team registration. */
export interface ManualPaymentFields {
  payment_method: PaymentMethod;
  /** Derived server-side: manual, unsettled, proof uploaded, not yet ruled on. */
  awaiting_verification: boolean;
  payment_proof_url: string | null;
  payment_proof_uploaded_at: string | null;
  /** Unpaid manual orders are cancelled after this and their quota released. */
  payment_deadline_at: string | null;
  rejected_reason: string | null;
  verified_at: string | null;
}

/** Shared by every "start a payment" response. */
export interface PaymentStart {
  snap_token: string | null;
  redirect_url: string | null;
  mock: boolean;
  payment_method: PaymentMethod;
  /** Present only when `payment_method` is `manual`. */
  bank_account: PublicBankAccount | null;
}

export interface RegisterTeamResult extends PaymentStart {
  team: Team;
}

export interface PayRegistrationResult extends PaymentStart {
  team: Team;
}

export interface PublicEvent {
  id: string;
  name: string;
  slug: string;
  sport_type: SportType;
  /** The sport itself, embedded so the UI needn't look it up. */
  sport: SportDef | null;
  status: EventStatus;
  start_date: string | null;
  end_date: string | null;
  /** The venue's zone. Kickoff times are UTC instants; this is what they mean. */
  timezone: string;
  registration_open: string | null;
  registration_close: string | null;
  registration_is_open: boolean;
  location_name: string | null;
  location_address: string | null;
  description: string | null;
  banner_url: string | null;
  /** The competitions inside this event; each carries its own format & fee. */
  categories: EventCategory[];
  tickets_on_sale: boolean;
  organization: { name: string | null; slug: string | null; logo_url: string | null };
  sponsors?: EventSponsor[];
  photos?: EventPhoto[];
  approved_teams_count: number;
  approved_teams?: PublicTeam[];
}

/** Public organizer profile — the outward-facing subset of Organization. */
export interface PublicOrganization {
  id: string;
  name: string;
  slug: string;
  logo_url: string | null;
  banner_url: string | null;
  description: string | null;
  contact_email: string | null;
  contact_phone: string | null;
  social_links: SocialLinks;
  published_events_count: number;
}

/** One row of the public event catalog — a trimmed PublicEvent, no relations. */
export interface PublicEventListItem {
  id: string;
  name: string;
  slug: string;
  sport_type: SportType;
  sport: SportDef | null;
  status: EventStatus;
  start_date: string | null;
  end_date: string | null;
  location_name: string | null;
  banner_url: string | null;
  /** How many competitions run inside this event. */
  categories_count?: number;
  /** Cheapest / dearest category fee, for the "mulai Rp …" card label. */
  registration_fee_min?: number;
  registration_fee_max?: number;
  registration_is_open: boolean;
  approved_teams_count: number;
  tickets_on_sale: boolean;
  organization: { name: string | null; slug: string | null; logo_url: string | null };
}

export interface PublicTeam {
  id: string;
  name: string;
  logo_url: string | null;
  players?: Player[] | null;
  officials?: TeamOfficial[] | null;
}

// ---- Tickets & payment (Phase 3) ----

export type TicketOrderStatus = "pending" | "paid" | "cancelled" | "refunded";

export interface TicketCategory {
  id: string;
  event_id: string;
  name: string;
  description: string | null;
  price: number;
  quota: number | null;
  sold: number;
  remaining: number | null;
  sale_start: string | null;
  sale_end: string | null;
  benefits: string[];
  is_transferable: boolean;
  is_active: boolean;
  is_on_sale: boolean;
  created_at?: string;
}

export interface Ticket {
  id: string;
  qr_code: string;
  holder_name: string | null;
  is_used: boolean;
  used_at: string | null;
  category?: { id: string; name: string };
}

export interface TicketOrder extends ManualPaymentFields {
  id: string;
  event_id: string;
  buyer_name: string;
  buyer_email: string;
  buyer_phone: string | null;
  quantity: number;
  unit_price: number;
  total_price: number;
  platform_fee: number;
  status: TicketOrderStatus;
  paid_at: string | null;
  created_at?: string;
  /** Where to transfer. Only sent while a manual order is still unpaid. */
  bank_account?: PublicBankAccount | null;
  category?: { id: string; name: string };
  event?: { id: string; name: string; start_date: string | null; location_name: string | null };
  tickets?: Ticket[];
}

export interface PurchaseResult extends PaymentStart {
  order: TicketOrder;
}

export type ScanResult = "valid" | "used" | "unpaid" | "invalid";

export interface ScanResponse {
  result: ScanResult;
  ticket?: {
    id: string;
    holder_name: string | null;
    category: string | null;
    used_at: string | null;
  };
}

export interface TicketReport {
  finance: {
    gross_revenue: number;
    platform_fee: number;
    paid_orders: number;
    tickets_sold: number;
  };
  checkin: {
    total: number;
    checked_in: number;
    remaining: number;
  };
  categories: {
    id: string;
    name: string;
    price: number;
    quota: number | null;
    sold: number;
    issued: number;
    checked_in: number;
  }[];
  recent_checkins: {
    id: string;
    holder_name: string | null;
    category: string | null;
    used_at: string | null;
  }[];
}

export interface UploadSignResult {
  key: string;
  upload_url: string | null;
  file_url: string;
  mock: boolean;
}

/* ---------------------------------------------------------------------------
 * Wallet & payouts
 *
 * Buyers pay the platform's Midtrans account, so an organizer's share is held
 * in a wallet: pending until the event is over, then available to withdraw.
 * -------------------------------------------------------------------------*/

export interface Wallet {
  id: string;
  organization_id: string;
  balance_available: number;
  /** Held until the event finishes. */
  balance_pending: number;
  /** Debited already, sitting in an open payout request. */
  balance_on_hold: number;
  total_earned: number;
  total_withdrawn: number;
  has_bank_account: boolean;
  has_active_withdrawal: boolean;
  rules: {
    minimum_withdrawal: number;
    admin_fee: number;
  };
}

export type WalletTxType = "credit" | "debit";

export type WalletTxStatus = "pending" | "available" | "cancelled";

export type WalletTxCategory =
  | "ticket_sale"
  | "registration_fee"
  | "refund"
  | "withdrawal"
  | "withdrawal_reversal"
  | "adjustment";

export interface WalletTransaction {
  id: string;
  event_id: string | null;
  event_name?: string | null;
  type: WalletTxType;
  category: WalletTxCategory;
  status: WalletTxStatus;
  amount: number;
  gross_amount: number;
  fee_amount: number;
  available_at: string | null;
  released_at: string | null;
  description: string | null;
  created_at: string;
}

export interface Paginated<T> {
  items: T[];
  meta: { page: number; last_page: number; total: number };
}

export interface BankAccount {
  id: string;
  organization_id: string;
  bank_name: string;
  bank_code: string | null;
  /** Masked for the organizer; full digits for the super admin who transfers. */
  account_number: string;
  account_holder: string;
  is_primary: boolean;
  created_at?: string;
}

export type WithdrawalStatus = "pending" | "processing" | "completed" | "rejected";

export interface Withdrawal {
  id: string;
  organization_id: string;
  organization_name?: string | null;
  reference: string;
  /** What the organizer receives. */
  amount: number;
  admin_fee: number;
  /** amount + admin_fee — what left the wallet. */
  total_debit: number;
  status: WithdrawalStatus;
  bank_name: string;
  bank_code: string | null;
  account_number: string;
  account_holder: string;
  note: string | null;
  proof_url: string | null;
  transfer_reference: string | null;
  admin_note: string | null;
  processed_at: string | null;
  completed_at: string | null;
  created_at: string;
}

/** A row in the admin's platform-wide payments list. */
export interface AdminPayment {
  id: string;
  kind: "ticket_order" | "team";
  reference: string | null;
  organization_name: string | null;
  event_name: string | null;
  payer: string | null;
  amount: number;
  platform_fee: number;
  status: string;
  paid_at: string | null;
}

export interface AdminWallet extends Wallet {
  organization_name: string | null;
}

/** A super-admin editable platform rule (payout policy). */
export interface PlatformSetting {
  key: string;
  label: string;
  /** Longer explanation for settings whose blast radius isn't obvious. */
  description: string | null;
  type: "money" | "int" | "bool";
  value: number | boolean;
  /** From config/wallet.php or config/payments.php — used when never overridden. */
  default: number | boolean;
  /** Null for `bool` — bounds are meaningless for a switch. */
  min: number | null;
  max: number | null;
  is_overridden: boolean;
}

export interface PlatformSettingsPayload {
  settings: PlatformSetting[];
  /**
   * Organizations with no primary bank account. They cannot be paid at all
   * while the gateway is off, so the admin sees this before flipping it.
   */
  orgs_without_bank_account: number;
}

// ---- Certificates ----

/** A placeable field key, e.g. "recipient_name" or "qr". Catalogued by the API. */
export type CertificateFieldKey = string;

export interface CertificateFieldDef {
  key: CertificateFieldKey;
  label: string;
}

/** One field placed on a template. x/y are percentages of the background. */
export interface CertificateField {
  key: CertificateFieldKey;
  x: number;
  y: number;
  size: number;
  color?: string;
  align?: "left" | "center" | "right";
  bold?: boolean;
  uppercase?: boolean;
}

export interface CertificateTemplate {
  id: string;
  organization_id: string;
  name: string;
  background_url: string;
  orientation: "landscape" | "portrait";
  fields: CertificateField[];
  certificates_count?: number;
  created_at: string;
}

export interface Certificate {
  id: string;
  event_id: string;
  event_name?: string;
  certificate_template_id: string | null;
  certificate_number: string;
  recipient_type: "team" | "player";
  recipient_id: string | null;
  recipient_name: string;
  team_name: string | null;
  /** The team manager's address — teams and players carry no email of their own. */
  recipient_email: string | null;
  award_title: string;
  has_pdf: boolean;
  issued_at: string;
  sent_at: string | null;
}

export interface CertificateRecipientTeam {
  id: string;
  name: string;
  email: string | null;
  players_count: number;
}

export interface CertificateRecipientPlayer {
  id: string;
  name: string;
  team_id: string;
  jersey_number: string | null;
}

export interface CertificateRecipients {
  teams: CertificateRecipientTeam[];
  players: CertificateRecipientPlayer[];
}

/** What the public QR lands on: proof the document is real. */
export interface CertificateVerification {
  certificate_number: string;
  recipient_name: string;
  team_name: string | null;
  award_title: string;
  issued_at: string;
  event: { name: string | null; start_date: string | null };
  organization: { name: string | null; slug: string | null; logo_url: string | null };
}

/** One logged-in user with their active device sessions (admin "Sesi Aktif"). */
export interface ActiveSessionDevice {
  id: string;
  device_info: string | null;
  ip_address: string | null;
  login_at: string | null;
}

export interface ActiveSession {
  id: string;
  full_name: string;
  email: string;
  role: string;
  avatar_url: string | null;
  /** Seen within the last few minutes. */
  online: boolean;
  last_seen_at: string | null;
  session_count: number;
  sessions: ActiveSessionDevice[];
}

/** A platform user as seen in the super-admin user-management list. */
export interface AdminUser {
  id: string;
  full_name: string;
  email: string;
  phone: string | null;
  avatar_url: string | null;
  role: "super_admin" | "user";
  default_mode: "organizer" | "participant";
  is_verified: boolean;
  email_verified_at: string | null;
  last_seen_at: string | null;
  owned_organizations: { id: string; name: string }[];
  memberships: { organization_id: string; organization_name: string | null; role: string }[];
  managed_teams: { id: string; name: string; event_name: string | null }[];
  /**
   * Jenis akun yang diturunkan server dari jejak user (punya/anggota organisasi
   * = organizer, mendaftarkan tim = peserta) — bukan `default_mode`, yang cuma
   * topi terakhir yang dipakai di switcher dashboard. Bisa keduanya sekaligus,
   * dan kosong untuk akun yang belum melakukan apa pun.
   */
  account_types: AccountType[];
}

export type AccountType = "organizer" | "participant";

// ---- Platform counters ----

export interface AdminEventStats {
  total: number;
  /**
   * Turnamen yang belum selesai dan belum dibatalkan — definisi yang sama
   * dengan kuota paket (`isActiveEvent` di `lib/plan.ts`), jadi draf ikut
   * terhitung. Event yang benar-benar sedang dimainkan ada di `ongoing`.
   */
  active: number;
  ongoing: number;
  /** Semua status yang dikenal server hadir, termasuk yang bernilai 0. */
  by_status: Record<EventStatus, number>;
}

export interface AdminStats {
  events: AdminEventStats;
}

// ---- Public page traffic ----

/** One day of a traffic trend. Trends are always gap-free, so `views` may be 0. */
export interface ViewTrendPoint {
  date: string;
  views: number;
  unique_visitors: number;
}

export interface ViewTotals {
  views: number;
  unique_visitors: number;
}

export interface EventViewStats {
  totals: ViewTotals;
  trend: ViewTrendPoint[];
}

export interface OrgEventViews {
  event_id: string;
  name: string;
  slug: string;
  status: string;
  views: number;
  unique_visitors: number;
}

/** Organizer-wide traffic: totals, trend, and a row per event. */
export interface OrgViewStats extends EventViewStats {
  events: OrgEventViews[];
}

export interface OrgViewBreakdown {
  organization_id: string;
  name: string;
  slug: string;
  views: number;
  unique_visitors: number;
  events_count: number;
}

export interface EventViewBreakdown {
  event_id: string;
  name: string;
  slug: string;
  organization_id: string;
  organization_name: string;
  views: number;
  unique_visitors: number;
}
