import { apiClient } from "./client";
import type { ApiEnvelope, EventPhoto, EventSponsor, SponsorTier } from "@/types/api";

// ---- Photo albums ----

export async function getPhotos(orgId: string, eventId: string): Promise<EventPhoto[]> {
  const { data } = await apiClient.get<ApiEnvelope<EventPhoto[]>>(
    `/organizations/${orgId}/events/${eventId}/photos`
  );
  return data.data;
}

export interface NewPhoto {
  photo_url: string;
  caption?: string | null;
}

/** Add several photos to one album at once. */
export async function addPhotos(
  orgId: string,
  eventId: string,
  album: string | null,
  photos: NewPhoto[]
): Promise<EventPhoto[]> {
  const { data } = await apiClient.post<ApiEnvelope<EventPhoto[]>>(
    `/organizations/${orgId}/events/${eventId}/photos`,
    { album, photos }
  );
  return data.data;
}

export async function deletePhoto(orgId: string, photoId: string): Promise<void> {
  await apiClient.delete(`/organizations/${orgId}/photos/${photoId}`);
}

// ---- Sponsors ----

export async function getSponsors(orgId: string, eventId: string): Promise<EventSponsor[]> {
  const { data } = await apiClient.get<ApiEnvelope<EventSponsor[]>>(
    `/organizations/${orgId}/events/${eventId}/sponsors`
  );
  return data.data;
}

export interface SponsorInput {
  name: string;
  logo_url: string;
  website_url?: string | null;
  tier?: SponsorTier;
}

export async function addSponsor(
  orgId: string,
  eventId: string,
  payload: SponsorInput
): Promise<EventSponsor> {
  const { data } = await apiClient.post<ApiEnvelope<EventSponsor>>(
    `/organizations/${orgId}/events/${eventId}/sponsors`,
    payload
  );
  return data.data;
}

export async function updateSponsor(
  orgId: string,
  sponsorId: string,
  payload: Partial<SponsorInput>
): Promise<EventSponsor> {
  const { data } = await apiClient.patch<ApiEnvelope<EventSponsor>>(
    `/organizations/${orgId}/sponsors/${sponsorId}`,
    payload
  );
  return data.data;
}

export async function deleteSponsor(orgId: string, sponsorId: string): Promise<void> {
  await apiClient.delete(`/organizations/${orgId}/sponsors/${sponsorId}`);
}
