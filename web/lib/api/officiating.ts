import { apiClient } from "./client";
import type { MatchResultPayload, MatchStatEntry } from "./matches";
import type {
  ApiEnvelope,
  Discipline,
  EventCategory,
  EventPersonnelKind,
  Match,
  MatchStatsData,
  SportEvent,
} from "@/types/api";

/**
 * The duty surface.
 *
 * Every path here is `/officiating/...`, a sibling of `/organizations/{org}/...`
 * and never a branch inside it. A task account holds no organization membership,
 * so the organizer endpoints answer 403 for these users by construction — which
 * is the point, not an obstacle to work around. If something a referee needs is
 * only available from an organizer route, the answer is a route on this prefix,
 * never a membership row.
 */

/** One row of "which events am I crew on". */
export interface OfficiatingEventSummary {
  personnel_id: string;
  event_id: string;
  event_name: string;
  event_status: string;
  start_date: string | null;
  end_date: string | null;
  location_name: string | null;
  kind: EventPersonnelKind;
  /** `role_label`, or "Wasit"/"Staf" when the organizer left it blank. */
  role_display: string;
}

/** Which hat this account wears on the event being viewed. */
export interface OfficiatingAssignmentDetail {
  personnel_id: string;
  kind: EventPersonnelKind;
  role_display: string;
}

export interface OfficiatingEventDetail {
  event: SportEvent;
  categories: EventCategory[];
  /**
   * Rides along with the event rather than being fetched separately: the whole
   * screen branches on it, and the client should not have to infer its own role
   * from which requests happen to 403.
   */
  assignment: OfficiatingAssignmentDetail;
}

export async function getOfficiatingEvents(): Promise<OfficiatingEventSummary[]> {
  const { data } =
    await apiClient.get<ApiEnvelope<OfficiatingEventSummary[]>>("/officiating/events");
  return data.data;
}

export async function getOfficiatingEvent(eventId: string): Promise<OfficiatingEventDetail> {
  const { data } = await apiClient.get<ApiEnvelope<OfficiatingEventDetail>>(
    `/officiating/events/${eventId}`,
  );
  return data.data;
}

export async function getOfficiatingMatches(
  eventId: string,
  categoryId: string,
): Promise<Match[]> {
  const { data } = await apiClient.get<ApiEnvelope<Match[]>>(
    `/officiating/events/${eventId}/categories/${categoryId}/matches`,
  );
  return data.data;
}

/**
 * Card tallies and playing bans, byte for byte what the organizer and the
 * public read — the same service answers all three. A crew-only tally would be
 * a third chance for the warning on a fixture card to disagree with the table
 * under it, which is the one thing this payload exists to prevent.
 */
export async function getOfficiatingDiscipline(
  eventId: string,
  categoryId: string,
): Promise<Discipline> {
  const { data } = await apiClient.get<ApiEnvelope<Discipline>>(
    `/officiating/events/${eventId}/categories/${categoryId}/discipline`,
  );
  return data.data;
}

// ---- Staff: the score sheet ----
//
// The three below are the organizer's own endpoints reached through the crew's
// door, so they take the same payload types rather than parallel copies of
// them: the shapes are validated by one service on the server, and two client
// types for one contract is how they stop matching.

/**
 * Record a result. **Never confirms it** — a task account holds no
 * organization_members row, so the server passes `$autoConfirm = false`
 * unconditionally here and the scoreline waits for an org admin to sign off.
 * That is not a client concern, but it is why the staff card says "menunggu
 * konfirmasi" where the organizer's says "Tersimpan".
 */
export async function updateOfficiatingResult(
  eventId: string,
  matchId: string,
  payload: MatchResultPayload,
): Promise<Match> {
  const { data } = await apiClient.patch<ApiEnvelope<Match>>(
    `/officiating/events/${eventId}/matches/${matchId}`,
    payload,
  );
  return data.data;
}

export async function getOfficiatingMatchStats(
  eventId: string,
  matchId: string,
): Promise<MatchStatsData> {
  const { data } = await apiClient.get<ApiEnvelope<MatchStatsData>>(
    `/officiating/events/${eventId}/matches/${matchId}/stats`,
  );
  return data.data;
}

/** Full replace, same as the organizer's: anything left out is deleted. */
export async function saveOfficiatingMatchStats(
  eventId: string,
  matchId: string,
  stats: MatchStatEntry[],
): Promise<null> {
  const { data } = await apiClient.put<ApiEnvelope<null>>(
    `/officiating/events/${eventId}/matches/${matchId}/stats`,
    { stats },
  );
  return data.data;
}
