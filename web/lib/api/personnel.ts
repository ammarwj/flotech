import { apiClient } from "./client";
import type { ApiEnvelope, EventPersonnel, EventPersonnelInput } from "@/types/api";

export async function getEventPersonnel(
  orgId: string,
  eventId: string
): Promise<EventPersonnel[]> {
  const { data } = await apiClient.get<ApiEnvelope<EventPersonnel[]>>(
    `/organizations/${orgId}/events/${eventId}/personnel`
  );
  return data.data;
}

/**
 * Replace the whole list. Sync contract: a row with an `id` is an update, one
 * without is new, and anything left out is deleted — so send every row you want
 * to keep, including the untouched ones.
 */
export async function syncEventPersonnel(
  orgId: string,
  eventId: string,
  personnel: EventPersonnelInput[]
): Promise<EventPersonnel[]> {
  const { data } = await apiClient.put<ApiEnvelope<EventPersonnel[]>>(
    `/organizations/${orgId}/events/${eventId}/personnel`,
    { personnel }
  );
  return data.data;
}
