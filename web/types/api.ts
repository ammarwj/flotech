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

export interface Match {
  id: string;
  round: number;
  group_name: string | null;
  order: number;
  home_team: MatchTeamRef | null;
  away_team: MatchTeamRef | null;
  home_team_id: string | null;
  away_team_id: string | null;
  home_score: number | null;
  away_score: number | null;
  status: MatchStatus;
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
  players?: Player[];
  documents?: TeamDocument[];
  event?: SportEvent;
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
  organization: { name: string | null; slug: string | null; logo_url: string | null };
  approved_teams_count: number;
  approved_teams?: { id: string; name: string; city: string | null; logo_url: string | null }[];
}

export interface UploadSignResult {
  key: string;
  upload_url: string | null;
  file_url: string;
  mock: boolean;
}
