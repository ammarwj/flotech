import { apiClient } from "./client";
import type {
  ApiEnvelope,
  PublicRaceOffer,
  RaceCategory,
  RaceCheckinPage,
  RaceEntry,
  RacePeriod,
  RaceReport,
  RaceScanResponse,
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
  /**
   * Who may enter. An empty list is stored as "no condition" — omitting the key
   * entirely (update only) leaves the stored conditions alone, so a client that
   * only renames a category does not quietly open it to everyone.
   */
  email_domains?: string[] | null;
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

/**
 * The status pills on the runner list.
 *
 * `awaiting_verification` sits beside the five `status` column values without
 * being one — it is derived from the proof columns, and the API answers it with
 * HasManualPayment's own scope. The headline already counts it, so leaving it
 * out of the pills would print a number with no way to see the rows behind it.
 */
export type RaceEntryFilter =
  | "all"
  | "paid"
  | "pending"
  | "awaiting_verification"
  | "cancelled"
  | "expired"
  | "refunded";

export interface RaceEntryPage {
  items: RaceEntry[];
  meta: {
    page: number;
    last_page: number;
    /**
     * How many rows the current filter matches — what the pager counts.
     * Distinct from `summary.total`, which ignores the status pill on purpose.
     */
    count: number;
  };
  /**
   * The cards above the list, NOT counts of `items`.
   *
   * Published rather than derived on the client because they are the event's
   * standing tally: counted from the loaded rows they would be measured against
   * a different population, and a fully paid event would read "120 pendaftar ·
   * 25 lunas" with nothing on screen to say which number to believe.
   *
   * All of them follow the search and the category but ignore the status pill,
   * the same call the buyer list's summary cards make — narrowing them would
   * have "Dibatalkan" read "12 pendaftar · 0 lunas" over a list doing exactly
   * what was asked.
   */
  summary: {
    total: number;
    paid: number;
    /** Manual, unsettled, proof in hand, not yet ruled on. */
    awaiting_verification: number;
    /** Of the PAID ones — an unpaid entry has no code to scan. */
    checked_in: number;
    /**
     * The organizer's net (`price`), not the gross the runner was charged: the
     * gateway and service fees on top are the platform's, and this is the
     * column the wallet credit reads.
     */
    revenue: number;
  };
  /** Every category the event runs, including ones nobody has entered. */
  categories: { id: string; name: string }[];
  /**
   * Every sale window the event runs, in sale order (not alphabetical — "Early
   * Bird" before "Pre Sale" is the order the Kelola page shows them in).
   * Like the categories, including ones nobody entered under.
   */
  periods: { id: string; name: string }[];
}

export async function getRaceEntries(
  orgId: string,
  eventId: string,
  params: {
    page?: number;
    per_page?: number;
    search?: string;
    status?: Exclude<RaceEntryFilter, "all">;
    category_id?: string;
    /**
     * The FK, not the `period_name` snapshot: a renamed window would otherwise
     * split its own runners into two groups.
     */
    period_id?: string;
  } = {}
): Promise<RaceEntryPage> {
  const { data } = await apiClient.get<ApiEnvelope<RaceEntryPage>>(
    `/organizations/${orgId}/events/${eventId}/race-entries`,
    { params }
  );
  return data.data;
}

/**
 * What an organizer may correct on one runner's row.
 *
 * Every key optional, and that is the contract the API enforces: one left out
 * leaves the stored value alone, while `null` on a nullable column really does
 * clear it.
 *
 * What is NOT here is the shape of the feature, not an omission — no
 * `race_category_id` (it decides the price, the quota and the email condition,
 * three answers this screen cannot ask for), no `price` or fee column (those
 * are snapshots, and a wallet credit already points at them), no `status`,
 * `qr_code` or `checked_in_at` (each has exactly one writer). See
 * UpdateRaceEntryRequest, which spells out each absence.
 */
export interface UpdateRaceEntryPayload {
  full_name?: string;
  email?: string;
  phone?: string | null;
  gender?: string | null;
  birth_date?: string | null;
  emergency_contact_name?: string | null;
  emergency_contact_phone?: string | null;
  emergency_contact_relation?: string | null;
  /**
   * The whole set, replaced. Sending the key at all means the server validates
   * it against the event's form — a required answer left out is a 422, not a
   * silent blank — so a caller changing only a name must omit it entirely.
   */
  custom_fields?: Record<string, string>;
  /** Free text: "A-014" and "5K-07" are both bib numbers. */
  bib_number?: string | null;
}

export async function updateRaceEntry(
  orgId: string,
  eventId: string,
  entryId: string,
  payload: UpdateRaceEntryPayload
): Promise<RaceEntry> {
  const { data } = await apiClient.patch<ApiEnvelope<RaceEntry>>(
    `/organizations/${orgId}/events/${eventId}/race-entries/${entryId}`,
    payload
  );
  return data.data;
}

/**
 * Cancel an entry and give its place back.
 *
 * This is the answer to "hapus peserta", and there is deliberately no delete:
 * a settled entry carries a wallet credit, a numbered receipt and a QR already
 * on somebody's phone, so removing the row would leave a balance claiming money
 * for a registration that no longer exists. The row survives with
 * `status: "cancelled"`, which is also what keeps it in every report.
 *
 * Refuses a paid entry with a 422 — that needs a refund, which is the platform
 * admin's to issue.
 */
export async function cancelRaceEntry(
  orgId: string,
  eventId: string,
  entryId: string
): Promise<RaceEntry> {
  const { data } = await apiClient.post<ApiEnvelope<RaceEntry>>(
    `/organizations/${orgId}/events/${eventId}/race-entries/${entryId}/cancel`,
    {}
  );
  return data.data;
}

// ---- Check-in desk (tenant-scoped) ----

/**
 * Scan a runner in.
 *
 * No `whole_order` flag, unlike the ticket gate: a runner is one person with
 * one place, and there is no party behind them to admit in the same call.
 */
export async function scanRaceEntry(
  orgId: string,
  eventId: string,
  qrCode: string
): Promise<RaceScanResponse> {
  const { data } = await apiClient.post<ApiEnvelope<RaceScanResponse>>(
    `/organizations/${orgId}/events/${eventId}/race-scan`,
    { qr_code: qrCode }
  );
  return data.data;
}

export async function getRaceReport(orgId: string, eventId: string): Promise<RaceReport> {
  const { data } = await apiClient.get<ApiEnvelope<RaceReport>>(
    `/organizations/${orgId}/events/${eventId}/race-report`
  );
  return data.data;
}

export async function getRaceCheckins(
  orgId: string,
  eventId: string,
  params: { page?: number; per_page?: number; q?: string } = {}
): Promise<RaceCheckinPage> {
  const { data } = await apiClient.get<ApiEnvelope<RaceCheckinPage>>(
    `/organizations/${orgId}/events/${eventId}/race-checkins`,
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
  emergency_contact_relation?: string | null;
  custom_fields?: Record<string, string>;
  documents?: {
    document_type: string;
    file_url: string;
    file_name?: string | null;
    file_size_bytes?: number | null;
  }[];
  payment_channel?: string | null;
  /**
   * Optional. Send only a code the preview accepted — an unpreviewed string is
   * not a code, and the amount the channel picker quoted came from that
   * preview.
   */
  discount_code?: string;
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

// ---- Participant (session-scoped) ----

/**
 * Fun run entries the signed-in account filed, newest first.
 *
 * Twin of `getMyTeams()`, and the second half of what makes the participant
 * dashboard complete: a runner registers no team at all, so before this the
 * page they landed on could only ever say "belum ada tim".
 *
 * A list and nothing more. Everything a runner can *do* to one entry stays on
 * the public proof page (`/race-entries/{id}`) the cards link to — the upload,
 * the invoice, the receipt, the QR — because a participant may have reached it
 * from an email rather than from here.
 */
export async function getMyRaceEntries(): Promise<RaceEntry[]> {
  const { data } = await apiClient.get<ApiEnvelope<RaceEntry[]>>("/my-race-entries");
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

/**
 * Ask for the entry fee back.
 *
 * On the public proof page beside the upload, not behind the participant
 * dashboard, for the reason written on `getRaceEntry()`: a runner may have
 * arrived from an email and never signed up, and `user_id` is nullable precisely
 * because an entry outlives the account that filed it.
 *
 * Whether the button may be shown at all is `entry.can_request_refund`, which
 * the server answers — do not reassemble it from `status`/`checked_in_at`.
 */
export async function requestRaceEntryRefund(
  entryId: string,
  reason: string
): Promise<RaceEntry> {
  const { data } = await apiClient.post<ApiEnvelope<RaceEntry>>(
    `/race-entries/${entryId}/refund-request`,
    { reason }
  );
  return data.data;
}

// ---- Organizer: the refund queue ----

/**
 * Runners asking for their entry fee back, and what was decided before.
 *
 * One payload for both rather than a `?status=` filter: the queue is usually
 * empty, and an empty screen that cannot show last week's decisions reads as a
 * feature that lost its data.
 */
export interface RaceRefundQueue {
  /** Oldest first — a queue of people waiting, not a feed. */
  pending: RaceEntry[];
  /** The last 50 rulings, newest first. */
  decided: RaceEntry[];
}

export async function getRaceRefunds(
  orgId: string,
  eventId: string
): Promise<RaceRefundQueue> {
  const { data } = await apiClient.get<ApiEnvelope<RaceRefundQueue>>(
    `/organizations/${orgId}/events/${eventId}/race-refunds`
  );
  return data.data;
}

/**
 * Grant it: the entry is voided, the organizer's wallet credit reversed, and
 * the place goes back on offer.
 *
 * The money does NOT reach the runner automatically — it sits in the platform's
 * Midtrans account, so the transfer back is a separate manual step. The success
 * message says so, and the dialog says it before the click.
 */
export async function approveRaceRefund(
  orgId: string,
  eventId: string,
  entryId: string
): Promise<RaceEntry> {
  const { data } = await apiClient.post<ApiEnvelope<RaceEntry>>(
    `/organizations/${orgId}/events/${eventId}/race-refunds/${entryId}/approve`
  );
  return data.data;
}

export async function rejectRaceRefund(
  orgId: string,
  eventId: string,
  entryId: string,
  reason: string
): Promise<RaceEntry> {
  const { data } = await apiClient.post<ApiEnvelope<RaceEntry>>(
    `/organizations/${orgId}/events/${eventId}/race-refunds/${entryId}/reject`,
    { reason }
  );
  return data.data;
}

/**
 * Refund a runner the organizer decided to refund, with nobody having asked —
 * the duplicate-payment case, where the person does not know they have a second
 * registration to query.
 *
 * Same money, same service, same manual transfer back as `approveRaceRefund`.
 * What differs is that the entry keeps `refund_requested_at` null, which is
 * what lets the runner's own page say "direfund penyelenggara" rather than
 * claiming they filed a request.
 *
 * Refuses (422) an entry that HAS an open request — that row's door is
 * `approveRaceRefund`, so the runner's own words stay standing as the question
 * — and one already checked in at the start line.
 *
 * The reason is required: the runner reads it on their own page, and it is the
 * only thing explaining why money came back on a registration they thought was
 * fine.
 */
export async function issueRaceRefund(
  orgId: string,
  eventId: string,
  entryId: string,
  reason: string
): Promise<RaceEntry> {
  const { data } = await apiClient.post<ApiEnvelope<RaceEntry>>(
    `/organizations/${orgId}/events/${eventId}/race-refunds/${entryId}/issue`,
    { reason }
  );
  return data.data;
}
