import { apiClient } from "./client";
import type {
  ApiEnvelope,
  Leaderboard,
  Match,
  MatchStatsData,
  MatchStatus,
  Standing,
} from "@/types/api";

// ---- Organizer (tenant-scoped) ----

export async function getMatches(orgId: string, eventId: string): Promise<Match[]> {
  const { data } = await apiClient.get<ApiEnvelope<Match[]>>(
    `/organizations/${orgId}/events/${eventId}/matches`
  );
  return data.data;
}

export async function generateSchedule(orgId: string, eventId: string): Promise<Match[]> {
  const { data } = await apiClient.post<ApiEnvelope<Match[]>>(
    `/organizations/${orgId}/events/${eventId}/schedule`
  );
  return data.data;
}

export async function getStandings(orgId: string, eventId: string): Promise<Standing[]> {
  const { data } = await apiClient.get<ApiEnvelope<Standing[]>>(
    `/organizations/${orgId}/events/${eventId}/standings`
  );
  return data.data;
}

export interface MatchResultPayload {
  home_score: number | null;
  away_score: number | null;
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

export async function getLeaderboard(orgId: string, eventId: string): Promise<Leaderboard> {
  const { data } = await apiClient.get<ApiEnvelope<Leaderboard>>(
    `/organizations/${orgId}/events/${eventId}/leaderboard`
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

export async function getPublicMatches(orgSlug: string, eventSlug: string): Promise<Match[]> {
  const { data } = await apiClient.get<ApiEnvelope<Match[]>>(
    `/public/events/${orgSlug}/${eventSlug}/matches`
  );
  return data.data;
}

export async function getPublicStandings(orgSlug: string, eventSlug: string): Promise<Standing[]> {
  const { data } = await apiClient.get<ApiEnvelope<Standing[]>>(
    `/public/events/${orgSlug}/${eventSlug}/standings`
  );
  return data.data;
}

export async function getPublicLeaderboard(orgSlug: string, eventSlug: string): Promise<Leaderboard> {
  const { data } = await apiClient.get<ApiEnvelope<Leaderboard>>(
    `/public/events/${orgSlug}/${eventSlug}/leaderboard`
  );
  return data.data;
}
