import { apiClient } from "./client";
import type { ApiEnvelope, EventDiscountCode } from "@/types/api";

/**
 * The organizer's own discount codes for one event.
 *
 * Twin of `lib/api/discount-codes.ts` (the super admin's plan codes) and kept
 * apart for the same reason the tables are: these belong to an event and the
 * organizer owns them. The participant-facing half is
 * `previewParticipantDiscount()` in `lib/api/discount.ts` — a code is typed by
 * the participant and checked server-side, never listed to them.
 */
export async function getEventDiscountCodes(
  orgId: string,
  eventId: string
): Promise<EventDiscountCode[]> {
  const { data } = await apiClient.get<ApiEnvelope<EventDiscountCode[]>>(
    `/organizations/${orgId}/events/${eventId}/discount-codes`
  );
  return data.data;
}

/** The counts are derived server-side, so neither is part of what the form submits. */
export type EventDiscountCodeInput = Omit<
  EventDiscountCode,
  "id" | "event_id" | "redeemed_count" | "reserved_count" | "created_at"
>;

export async function createEventDiscountCode(
  orgId: string,
  eventId: string,
  payload: EventDiscountCodeInput
): Promise<EventDiscountCode> {
  const { data } = await apiClient.post<ApiEnvelope<EventDiscountCode>>(
    `/organizations/${orgId}/events/${eventId}/discount-codes`,
    payload
  );
  return data.data;
}

export async function updateEventDiscountCode(
  orgId: string,
  codeId: string,
  payload: EventDiscountCodeInput
): Promise<EventDiscountCode> {
  const { data } = await apiClient.patch<ApiEnvelope<EventDiscountCode>>(
    `/organizations/${orgId}/event-discount-codes/${codeId}`,
    payload
  );
  return data.data;
}

export async function deleteEventDiscountCode(orgId: string, codeId: string): Promise<void> {
  await apiClient.delete(`/organizations/${orgId}/event-discount-codes/${codeId}`);
}
