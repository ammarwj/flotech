import { apiClient } from "./client";
import type {
  ApiEnvelope,
  EventCategory,
  EventPersonnelKind,
  Match,
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
