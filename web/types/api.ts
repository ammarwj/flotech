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
  price_monthly: number;
  price_yearly: number;
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

export interface Organization {
  id: string;
  name: string;
  slug: string;
  logo_url: string | null;
  description: string | null;
  contact_email: string | null;
  contact_phone: string | null;
  custom_domain: string | null;
  owner_id: string;
  plan_id: string | null;
  plan_expires_at: string | null;
  plan?: Plan;
}

export type SubscriptionStatus = "active" | "past_due" | "cancelled" | "expired";

export interface Subscription {
  id: string;
  organization_id: string;
  plan_id: string | null;
  /** Issued at checkout — every subscription has one, paid or not. */
  invoice_number: string | null;
  /** Issued on payment — only paid subscriptions have one. */
  receipt_number: string | null;
  billing_cycle: "monthly" | "yearly";
  amount: number;
  status: SubscriptionStatus;
  starts_at: string | null;
  expires_at: string | null;
  midtrans_order_id: string | null;
  payment_type: string | null;
  paid_at: string | null;
  plan?: Plan;
}

export interface CheckoutResult {
  subscription: Subscription;
  snap_token: string | null;
  redirect_url: string | null;
  mock: boolean;
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

export interface SportStatDef {
  key: string;
  label: string;
  short: string;
  /** 'goal' cross-checks the score, 'assist' can't outnumber the goals. */
  role: "goal" | "assist" | null;
  /** Weight in the fair-play tiebreaker (yellow 1, red 3). 0 = not misconduct. */
  fair_play_weight: number;
}

export interface SportDef {
  slug: string;
  name: string;
  color: string;
  icon: string | null;
  scoring: "goal" | "set";
  default_match_minutes: number;
  stats: SportStatDef[];
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
  city: string | null;
  logo_url: string | null;
}

export type BracketSide = "winners" | "losers" | "grand_final";

/** Stage of a hybrid event; null for the single-stage formats. */
export type MatchStage = "group" | "knockout" | null;

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
  status: MatchStatus;
  /** True once the result is confirmed (counts toward standings/bracket). */
  confirmed: boolean;
  scheduled_at: string | null;
  venue: string | null;
}

/** A place in the knockout bracket, e.g. "Juara Grup A" — and who holds it now. */
export interface KnockoutSlot {
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
  group_matches_pending: number;
  ties: { order: number; home: KnockoutSlot | null; away: KnockoutSlot | null }[];
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
  goals_for: number;
  goals_against: number;
  goal_diff: number;
  points: number;
  /** Disciplinary points: 1 per yellow, 3 per red. Lower is better. */
  fair_play: number;
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

export interface SportEvent {
  id: string;
  organization_id: string;
  name: string;
  slug: string;
  sport_type: SportType;
  /** The sport itself, embedded so the UI needn't look it up. */
  sport: SportDef | null;
  tournament_format: TournamentFormat;
  /** The engine the format runs on — branch on this, not the format key. */
  engine: FormatEngine | null;
  status: EventStatus;
  start_date: string | null;
  end_date: string | null;
  registration_open: string | null;
  registration_close: string | null;
  location_name: string | null;
  location_address: string | null;
  description: string | null;
  banner_url: string | null;
  max_teams: number | null;
  registration_fee: number;
  bracket_config: BracketConfig | null;
  teams_count?: number;
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
}

export interface TeamDocument {
  id?: string;
  document_type?: string | null;
  file_name?: string | null;
  file_url: string;
}

export type PaymentStatus = "unpaid" | "paid";

export interface Team {
  id: string;
  event_id: string;
  name: string;
  logo_url: string | null;
  city: string | null;
  jersey_color: string | null;
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
  documents?: TeamDocument[];
  event?: SportEvent;
}

export interface RegisterTeamResult {
  team: Team;
  snap_token: string | null;
  redirect_url: string | null;
  mock: boolean;
}

export interface PayRegistrationResult {
  team: Team;
  snap_token: string | null;
  redirect_url: string | null;
  mock: boolean;
}

export interface PublicEvent {
  id: string;
  name: string;
  slug: string;
  sport_type: SportType;
  /** The sport itself, embedded so the UI needn't look it up. */
  sport: SportDef | null;
  tournament_format: TournamentFormat;
  /** The engine the format runs on — branch on this, not the format key. */
  engine: FormatEngine | null;
  status: EventStatus;
  start_date: string | null;
  end_date: string | null;
  registration_open: string | null;
  registration_close: string | null;
  registration_is_open: boolean;
  location_name: string | null;
  location_address: string | null;
  description: string | null;
  banner_url: string | null;
  max_teams: number | null;
  registration_fee: number;
  bracket_config: BracketConfig | null;
  tickets_on_sale: boolean;
  organization: { name: string | null; slug: string | null; logo_url: string | null };
  sponsors?: EventSponsor[];
  photos?: EventPhoto[];
  approved_teams_count: number;
  approved_teams?: PublicTeam[];
}

export interface PublicTeam {
  id: string;
  name: string;
  city: string | null;
  logo_url: string | null;
  players?: (Player & { photo_url?: string | null })[] | null;
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

export interface TicketOrder {
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
  category?: { id: string; name: string };
  event?: { id: string; name: string; start_date: string | null; location_name: string | null };
  tickets?: Ticket[];
}

export interface PurchaseResult {
  order: TicketOrder;
  snap_token: string | null;
  redirect_url: string | null;
  mock: boolean;
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
  type: "money" | "int";
  value: number;
  /** From config/wallet.php — used when never overridden. */
  default: number;
  min: number;
  max: number;
  is_overridden: boolean;
}
