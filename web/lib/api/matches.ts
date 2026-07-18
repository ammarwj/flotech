import { apiClient } from "./client";
import type {
  ApiEnvelope,
  DrawMethod,
  KnockoutPlan,
  Leaderboard,
  Match,
  MatchStatsData,
  MatchStatus,
  PublicMatchStats,
  Standing,
  Team,
} from "@/types/api";

// ---- Organizer (tenant-scoped) ----

export async function getMatches(
  orgId: string,
  eventId: string,
  categoryId: string
): Promise<Match[]> {
  const { data } = await apiClient.get<ApiEnvelope<Match[]>>(
    `/organizations/${orgId}/events/${eventId}/categories/${categoryId}/matches`
  );
  return data.data;
}

export interface CreateMatchPayload {
  home_team_id: string;
  away_team_id: string;
  /** ISO kickoff time; null when undecided. */
  scheduled_at?: string | null;
  venue?: string | null;
}

/** Add a single fixture by hand (organizers with their own schedule). */
export async function createMatch(
  orgId: string,
  eventId: string,
  categoryId: string,
  payload: CreateMatchPayload
): Promise<Match> {
  const { data } = await apiClient.post<ApiEnvelope<Match>>(
    `/organizations/${orgId}/events/${eventId}/categories/${categoryId}/matches`,
    payload
  );
  return data.data;
}

/** Delete a fixture (manual or generated). */
export async function deleteMatch(orgId: string, matchId: string): Promise<null> {
  const { data } = await apiClient.delete<ApiEnvelope<null>>(
    `/organizations/${orgId}/matches/${matchId}`
  );
  return data.data;
}

export interface ScheduleOptions {
  /** Date of the first matchday (YYYY-MM-DD); defaults to the event start. */
  start_date?: string | null;
  /** Daily kickoff window, "HH:mm". */
  daily_start?: string;
  daily_end?: string;
  /** Match duration and break between consecutive slots, in minutes. */
  match_minutes?: number;
  break_minutes?: number;
  /** Parallel venues/courts that can host matches in the same slot. */
  venues?: number;
  /** Cap on matches scheduled per day. */
  max_per_day?: number | null;
  /** Spread rounds across the event date range instead of packing days. */
  spread?: boolean;
}

export async function generateSchedule(
  orgId: string,
  eventId: string,
  categoryId: string,
  options?: ScheduleOptions
): Promise<Match[]> {
  const { data } = await apiClient.post<ApiEnvelope<Match[]>>(
    `/organizations/${orgId}/events/${eventId}/categories/${categoryId}/schedule`,
    options ?? {}
  );
  return data.data;
}

export interface DrawPayload {
  method: DrawMethod;
  /** Manual draw: team id => group name. */
  assignments?: Record<string, string>;
  /** Pot draw: team id => pot number. */
  pots?: Record<string, number>;
}

/** Draw the approved teams of a hybrid event into groups. */
export async function drawGroups(
  orgId: string,
  eventId: string,
  categoryId: string,
  payload: DrawPayload
): Promise<Team[]> {
  const { data } = await apiClient.post<ApiEnvelope<Team[]>>(
    `/organizations/${orgId}/events/${eventId}/categories/${categoryId}/draw`,
    payload
  );
  return data.data;
}

/** The planned knockout bracket ("Juara Grup A" v "Runner-up Grup D"). */
export async function getKnockoutPlan(
  orgId: string,
  eventId: string,
  categoryId: string
): Promise<KnockoutPlan> {
  const { data } = await apiClient.get<ApiEnvelope<KnockoutPlan>>(
    `/organizations/${orgId}/events/${eventId}/categories/${categoryId}/knockout-plan`
  );
  return data.data;
}

/** Build the knockout bracket of a hybrid category from the group qualifiers. */
export async function generateKnockout(
  orgId: string,
  eventId: string,
  categoryId: string
): Promise<Match[]> {
  const { data } = await apiClient.post<ApiEnvelope<Match[]>>(
    `/organizations/${orgId}/events/${eventId}/categories/${categoryId}/knockout`
  );
  return data.data;
}

/** Drop the knockout bracket, back to the plan. Group fixtures survive. */
export async function deleteKnockout(
  orgId: string,
  eventId: string,
  categoryId: string
): Promise<Match[]> {
  const { data } = await apiClient.delete<ApiEnvelope<Match[]>>(
    `/organizations/${orgId}/events/${eventId}/categories/${categoryId}/knockout`
  );
  return data.data;
}

export async function confirmResult(
  orgId: string,
  matchId: string,
  confirmed: boolean
): Promise<Match> {
  const { data } = await apiClient.patch<ApiEnvelope<Match>>(
    `/organizations/${orgId}/matches/${matchId}/confirm`,
    { confirmed }
  );
  return data.data;
}

export async function getStandings(
  orgId: string,
  eventId: string,
  categoryId: string
): Promise<Standing[]> {
  const { data } = await apiClient.get<ApiEnvelope<Standing[]>>(
    `/organizations/${orgId}/events/${eventId}/categories/${categoryId}/standings`
  );
  return data.data;
}

export interface MatchResultPayload {
  home_score?: number | null;
  away_score?: number | null;
  /** Shootout score; required when a knockout tie ends level. */
  home_penalty?: number | null;
  away_penalty?: number | null;
  /** For set-based sports; backend derives home/away score from this. */
  sets?: { home: number; away: number }[] | null;
  status: MatchStatus;
  scheduled_at?: string | null;
  venue?: string | null;
}

export async function updateMatchResult(
  orgId: string,
  matchId: string,
  payload: MatchResultPayload
): Promise<Match> {
  const { data } = await apiClient.patch<ApiEnvelope<Match>>(
    `/organizations/${orgId}/matches/${matchId}`,
    payload
  );
  return data.data;
}

export async function getLeaderboard(
  orgId: string,
  eventId: string,
  categoryId: string
): Promise<Leaderboard> {
  const { data } = await apiClient.get<ApiEnvelope<Leaderboard>>(
    `/organizations/${orgId}/events/${eventId}/categories/${categoryId}/leaderboard`
  );
  return data.data;
}

export interface MatchSchedulePayload {
  scheduled_at: string | null;
  venue?: string | null;
}

/** Update only kickoff time / venue, leaving any result untouched. */
export async function updateMatchSchedule(
  orgId: string,
  matchId: string,
  payload: MatchSchedulePayload
): Promise<Match> {
  const { data } = await apiClient.patch<ApiEnvelope<Match>>(
    `/organizations/${orgId}/matches/${matchId}/schedule`,
    payload
  );
  return data.data;
}

export async function getMatchStats(orgId: string, matchId: string): Promise<MatchStatsData> {
  const { data } = await apiClient.get<ApiEnvelope<MatchStatsData>>(
    `/organizations/${orgId}/matches/${matchId}/stats`
  );
  return data.data;
}

export interface MatchStatEntry {
  player_id: string;
  stat_key: string;
  value: number;
}

export async function saveMatchStats(
  orgId: string,
  matchId: string,
  stats: MatchStatEntry[]
): Promise<null> {
  const { data } = await apiClient.put<ApiEnvelope<null>>(
    `/organizations/${orgId}/matches/${matchId}/stats`,
    { stats }
  );
  return data.data;
}

// ---- Public ----

export async function getPublicMatches(
  orgSlug: string,
  eventSlug: string,
  categorySlug: string
): Promise<Match[]> {
  const { data } = await apiClient.get<ApiEnvelope<Match[]>>(
    `/public/events/${orgSlug}/${eventSlug}/categories/${categorySlug}/matches`
  );
  return data.data;
}

export async function getPublicStandings(
  orgSlug: string,
  eventSlug: string,
  categorySlug: string
): Promise<Standing[]> {
  const { data } = await apiClient.get<ApiEnvelope<Standing[]>>(
    `/public/events/${orgSlug}/${eventSlug}/categories/${categorySlug}/standings`
  );
  return data.data;
}

export async function getPublicLeaderboard(
  orgSlug: string,
  eventSlug: string,
  categorySlug: string
): Promise<Leaderboard> {
  const { data } = await apiClient.get<ApiEnvelope<Leaderboard>>(
    `/public/events/${orgSlug}/${eventSlug}/categories/${categorySlug}/leaderboard`
  );
  return data.data;
}

/** Player stats of one fixture, for the public match detail. */
export async function getPublicMatchStats(
  orgSlug: string,
  eventSlug: string,
  matchId: string
): Promise<PublicMatchStats> {
  const { data } = await apiClient.get<ApiEnvelope<PublicMatchStats>>(
    `/public/events/${orgSlug}/${eventSlug}/matches/${matchId}/stats`
  );
  return data.data;
}
