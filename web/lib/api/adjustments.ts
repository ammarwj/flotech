import { apiClient } from "./client";
import type { ApiEnvelope, StandingAdjustment } from "@/types/api";

/**
 * A category's manual point ledger — the organizer's house rules ("suporter
 * datang lengkap = +2 poin").
 *
 * Its own module rather than a corner of matches.ts: a ledger entry is not a
 * fixture, and nothing here reads or writes one.
 *
 * Append and remove, with no update: correcting an entry means deleting it and
 * typing it again, so the author on a row is always the author of the
 * statement. There is deliberately no sync call either — entries accrue across
 * a tournament from several people, so a client sending the whole list would
 * delete the ones it never saw.
 */
export async function getAdjustments(
  orgId: string,
  eventId: string,
  categoryId: string
): Promise<StandingAdjustment[]> {
  const { data } = await apiClient.get<ApiEnvelope<StandingAdjustment[]>>(
    `/organizations/${orgId}/events/${eventId}/categories/${categoryId}/adjustments`
  );
  return data.data;
}

export async function createAdjustment(
  orgId: string,
  eventId: string,
  categoryId: string,
  payload: { team_id: string; points: number; reason: string }
): Promise<StandingAdjustment> {
  const { data } = await apiClient.post<ApiEnvelope<StandingAdjustment>>(
    `/organizations/${orgId}/events/${eventId}/categories/${categoryId}/adjustments`,
    payload
  );
  return data.data;
}

export async function deleteAdjustment(
  orgId: string,
  adjustmentId: string
): Promise<void> {
  await apiClient.delete(`/organizations/${orgId}/adjustments/${adjustmentId}`);
}
