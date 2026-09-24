import { apiClient } from "./client";
import { downloadBlob, fileNameFromDisposition, unpackBlobError } from "@/lib/download";
import type { MatchResultPayload, MatchStatEntry } from "./matches";
import type {
  ApiEnvelope,
  Discipline,
  EventCategory,
  EventPersonnelKind,
  Match,
  MatchLineup,
  MatchLineupsData,
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

/**
 * The sheet for the IP table.
 *
 * Refuses (422) until the referee has signed off **both** sides — the gate lives
 * on the server and is not mirrored here: a client that decided for itself when
 * printing is allowed would be a second reader of the same rule, and the two
 * would disagree the first time a sheet was approved in another tab.
 *
 * Through apiClient with `responseType: "blob"`, never a plain `<a href>`: the
 * access token lives in memory, so a direct link to the API would 401. Same rule
 * as the exports and the billing documents.
 */
export async function downloadOfficiatingLineupSheet(
  eventId: string,
  matchId: string,
): Promise<void> {
  try {
    const response = await apiClient.get<Blob>(
      `/officiating/events/${eventId}/matches/${matchId}/lineup-sheet`,
      { responseType: "blob" },
    );

    downloadBlob(
      response.data,
      fileNameFromDisposition(response.headers["content-disposition"], "susunan-pemain.pdf"),
    );
  } catch (err) {
    // The refusal is the feature here: without this the 422 arrives as a Blob
    // and its message — which half is still unapproved — is lost.
    throw await unpackBlobError(err);
  }
}

/**
 * Laporan pertandingan — the staff's twin of the organizer's route.
 *
 * One controller, one gate, and it is a different gate from the sheet above:
 * that one waits for the referee's approval, this one waits for the result. The
 * server refuses (422) until the fixture is finished with a scoreline, and that
 * rule is not mirrored here for the reason already written above.
 */
export async function downloadOfficiatingMatchReport(
  eventId: string,
  matchId: string,
): Promise<void> {
  try {
    const response = await apiClient.get<Blob>(
      `/officiating/events/${eventId}/matches/${matchId}/report`,
      { responseType: "blob" },
    );

    downloadBlob(
      response.data,
      fileNameFromDisposition(
        response.headers["content-disposition"],
        "laporan-pertandingan.pdf",
      ),
    );
  } catch (err) {
    throw await unpackBlobError(err);
  }
}

// ---- Referee: approving the team sheets ----

export async function getMatchLineups(
  eventId: string,
  matchId: string,
): Promise<MatchLineupsData> {
  const { data } = await apiClient.get<ApiEnvelope<MatchLineupsData>>(
    `/officiating/events/${eventId}/matches/${matchId}/lineups`,
  );
  return data.data;
}

/**
 * Accept one team's sheet. There is no way back: the print gate reads
 * `approved`, so an un-approve would also reopen editing on a sheet that may
 * already be on the table.
 */
export async function approveLineup(
  eventId: string,
  lineupId: string,
): Promise<MatchLineup> {
  const { data } = await apiClient.post<ApiEnvelope<MatchLineup>>(
    `/officiating/events/${eventId}/lineups/${lineupId}/approve`,
  );
  return data.data;
}

/** Hand it back with the reason the manager has to answer — required. */
export async function rejectLineup(
  eventId: string,
  lineupId: string,
  note: string,
): Promise<MatchLineup> {
  const { data } = await apiClient.post<ApiEnvelope<MatchLineup>>(
    `/officiating/events/${eventId}/lineups/${lineupId}/reject`,
    { note },
  );
  return data.data;
}
