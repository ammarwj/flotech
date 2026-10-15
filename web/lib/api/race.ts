import { apiClient } from "./client";
import type {
  ApiEnvelope,
  PublicRaceOffer,
  RaceCategory,
  RaceEntry,
  RacePeriod,
  RegisterRaceEntryResult,
} from "@/types/api";

/**
 * Fun run: the categories a race sells, the windows that price them, and the
 * runners who entered.
 *
 * Its own client rather than more exports in `events.ts`, mirroring the backend
 * split: nothing here touches a competition category, a fixture or a roster.
 */

export interface RacePeriodInput {
  /** Present = update that window, absent = a new one. The sync contract. */
  id?: string;
  name: string;
  /** Offset-bearing ISO strings — a sale opens on the venue's clock. */
  starts_at: string;
  ends_at: string;
}

export interface RaceCategoryPriceInput {
  race_period_id: string;
  price: number;
}

export interface RaceCategoryInput {
  name: string;
  description?: string | null;
  quota?: number | null;
  is_active?: boolean;
  /**
   * The price matrix. A window left out of this list is NOT SOLD for this
   * category — never free, never inherited. Omitting the key entirely (update
   * only) leaves the stored matrix alone; sending `[]` clears it.
   */
  prices?: RaceCategoryPriceInput[];
}

// ---- Organizer (tenant-scoped) ----

export async function getRacePeriods(orgId: string, eventId: string): Promise<RacePeriod[]> {
  const { data } = await apiClient.get<ApiEnvelope<{ periods: RacePeriod[] }>>(
    `/organizations/${orgId}/events/${eventId}/race-periods`
  );
  return data.data.periods;
}

/**
 * Replace the whole set of windows.
 *
 * A PUT, not per-row CRUD: the windows only mean anything as a sequence, and
 * "does this overlap the next" cannot be answered a row at a time. The server
 * refuses an overlap and refuses deleting a window somebody has entered under.
 */
export async function saveRacePeriods(
  orgId: string,
  eventId: string,
  periods: RacePeriodInput[]
): Promise<RacePeriod[]> {
  const { data } = await apiClient.put<ApiEnvelope<{ periods: RacePeriod[] }>>(
    `/organizations/${orgId}/events/${eventId}/race-periods`,
    { periods }
  );
  return data.data.periods;
}

export async function getRaceCategories(orgId: string, eventId: string): Promise<RaceCategory[]> {
  const { data } = await apiClient.get<ApiEnvelope<RaceCategory[]>>(
    `/organizations/${orgId}/events/${eventId}/race-categories`
  );
  return data.data;
}

export async function createRaceCategory(
  orgId: string,
  eventId: string,
  input: RaceCategoryInput
): Promise<RaceCategory> {
  const { data } = await apiClient.post<ApiEnvelope<RaceCategory>>(
    `/organizations/${orgId}/events/${eventId}/race-categories`,
    input
  );
  return data.data;
}

export async function updateRaceCategory(
  orgId: string,
  categoryId: string,
  input: Partial<RaceCategoryInput>
): Promise<RaceCategory> {
  const { data } = await apiClient.patch<ApiEnvelope<RaceCategory>>(
    `/organizations/${orgId}/race-categories/${categoryId}`,
    input
  );
  return data.data;
}

export async function deleteRaceCategory(orgId: string, categoryId: string): Promise<void> {
  await apiClient.delete(`/organizations/${orgId}/race-categories/${categoryId}`);
}

export interface RaceEntryPage {
  items: RaceEntry[];
  meta: { page: number; last_page: number; total: number };
}

export async function getRaceEntries(
  orgId: string,
  eventId: string,
  params: { page?: number; per_page?: number; search?: string; status?: string; category_id?: string } = {}
): Promise<RaceEntryPage> {
  const { data } = await apiClient.get<ApiEnvelope<RaceEntryPage>>(
    `/organizations/${orgId}/events/${eventId}/race-entries`,
    { params }
  );
  return data.data;
}

// ---- Public ----

/**
 * What a fun run is selling, with the windows those prices belong to.
 *
 * One request for both, because the page prints a price and the date it changes
 * in the same sentence — two requests would let them disagree across a boundary.
 */
export async function getPublicRace(
  orgSlug: string,
  eventSlug: string
): Promise<PublicRaceOffer> {
  const { data } = await apiClient.get<ApiEnvelope<PublicRaceOffer>>(
    `/public/events/${orgSlug}/${eventSlug}/race`
  );
  return data.data;
}

export interface RegisterRaceEntryPayload {
  race_category_id: string;
  full_name: string;
  email: string;
  phone?: string | null;
  gender?: string | null;
  birth_date?: string | null;
  emergency_contact_name?: string | null;
  emergency_contact_phone?: string | null;
  custom_fields?: Record<string, string>;
  documents?: {
    document_type: string;
    file_url: string;
    file_name?: string | null;
    file_size_bytes?: number | null;
  }[];
  payment_channel?: string | null;
}

/**
 * Enter the race.
 *
 * Note what the payload does NOT carry: a period id or a price. The window is
 * picked by the server's clock and the amount resolved from it, so asking for
 * the early-bird price is not a request anyone can send.
 */
export async function registerRaceEntry(
  orgSlug: string,
  eventSlug: string,
  payload: RegisterRaceEntryPayload
): Promise<RegisterRaceEntryResult> {
  const { data } = await apiClient.post<ApiEnvelope<RegisterRaceEntryResult>>(
    `/public/events/${orgSlug}/${eventSlug}/race/register`,
    payload
  );
  return data.data;
}

export async function getRaceEntry(entryId: string): Promise<RaceEntry> {
  const { data } = await apiClient.get<ApiEnvelope<RaceEntry>>(`/race-entries/${entryId}`);
  return data.data;
}

export async function submitRaceEntryProof(
  entryId: string,
  proofUrl: string
): Promise<RaceEntry> {
  const { data } = await apiClient.post<ApiEnvelope<RaceEntry>>(
    `/race-entries/${entryId}/proof`,
    { payment_proof_url: proofUrl }
  );
  return data.data;
}
