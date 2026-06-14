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

export interface Subscription {
  id: string;
  organization_id: string;
  plan_id: string | null;
  billing_cycle: "monthly" | "yearly";
  amount: number;
  status: "active" | "past_due" | "cancelled" | "expired";
  starts_at: string | null;
  expires_at: string | null;
  midtrans_order_id: string | null;
  paid_at: string | null;
  plan?: Plan;
}

export interface CheckoutResult {
  subscription: Subscription;
  snap_token: string | null;
  redirect_url: string | null;
  mock: boolean;
}

export type SportType = "football" | "futsal" | "badminton" | "padel" | "volleyball";
export type TournamentFormat = "league" | "knockout_single" | "knockout_double" | "hybrid";
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

export interface Match {
  id: string;
  round: number;
  group_name: string | null;
  bracket: BracketSide | null;
  order: number;
  home_team: MatchTeamRef | null;
  away_team: MatchTeamRef | null;
  home_team_id: string | null;
  away_team_id: string | null;
  home_score: number | null;
  away_score: number | null;
  /** Per-set scores for set-based sports; null for goal-based sports. */
  sets: { home: number; away: number }[] | null;
  status: MatchStatus;
  /** True once the result is confirmed (counts toward standings/bracket). */
  confirmed: boolean;
  scheduled_at: string | null;
  venue: string | null;
}

export interface Standing {
  rank: number;
  team: MatchTeamRef;
  played: number;
  won: number;
  drawn: number;
  lost: number;
  goals_for: number;
  goals_against: number;
  goal_diff: number;
  points: number;
}

export interface StatColumn {
  key: string;
  label: string;
  short: string;
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
  tournament_format: TournamentFormat;
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
  teams_count?: number;
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
  tournament_format: TournamentFormat;
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
  tickets_on_sale: boolean;
  organization: { name: string | null; slug: string | null; logo_url: string | null };
  approved_teams_count: number;
  approved_teams?: { id: string; name: string; city: string | null; logo_url: string | null }[];
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
