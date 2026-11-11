import { apiClient } from "./client";
import { downloadBlob, fileNameFromDisposition } from "@/lib/download";
import type {
  ApiEnvelope,
  IdCardBatch,
  IdCardField,
  IdCardFieldDef,
  IdCardIssuedPage,
  IdCardRecipient,
  IdCardTemplate,
  IdCardVerification,
} from "@/types/api";

// ---- Templates ----

/**
 * The fields a card may place: the fixed catalogue from config/id_card.php,
 * plus — when `eventId` is given — the questions that event's registration form
 * asks, which is what makes an answer the entrant typed placeable at all.
 *
 * The event is a lens, not the template's owner: templates are org-scoped and
 * reused, so one placing a question from another event stays valid and simply
 * renders blank where that event never asked it.
 */
export async function getIdCardFields(
  orgId: string,
  eventId?: string
): Promise<IdCardFieldDef[]> {
  const { data } = await apiClient.get<ApiEnvelope<IdCardFieldDef[]>>(
    `/organizations/${orgId}/id-card-fields`,
    { params: eventId ? { event_id: eventId } : undefined }
  );
  return data.data;
}

export async function getIdCardTemplates(orgId: string): Promise<IdCardTemplate[]> {
  const { data } = await apiClient.get<ApiEnvelope<IdCardTemplate[]>>(
    `/organizations/${orgId}/id-card-templates`
  );
  return data.data;
}

export interface IdCardTemplateInput {
  name: string;
  background_url: string;
  /** Millimetres. The presets never reach the API — they just write two numbers. */
  width_mm: number;
  height_mm: number;
  fields: IdCardField[];
}

export async function createIdCardTemplate(
  orgId: string,
  payload: IdCardTemplateInput
): Promise<IdCardTemplate> {
  const { data } = await apiClient.post<ApiEnvelope<IdCardTemplate>>(
    `/organizations/${orgId}/id-card-templates`,
    payload
  );
  return data.data;
}

export async function updateIdCardTemplate(
  orgId: string,
  templateId: string,
  payload: Partial<IdCardTemplateInput>
): Promise<IdCardTemplate> {
  const { data } = await apiClient.patch<ApiEnvelope<IdCardTemplate>>(
    `/organizations/${orgId}/id-card-templates/${templateId}`,
    payload
  );
  return data.data;
}

export async function deleteIdCardTemplate(orgId: string, templateId: string): Promise<void> {
  await apiClient.delete(`/organizations/${orgId}/id-card-templates/${templateId}`);
}

// ---- Batches ----

export async function getIdCardRecipients(
  orgId: string,
  eventId: string
): Promise<IdCardRecipient[]> {
  const { data } = await apiClient.get<ApiEnvelope<IdCardRecipient[]>>(
    `/organizations/${orgId}/events/${eventId}/id-card-recipients`
  );
  return data.data;
}

/**
 * Queue a batch and get back the id to poll.
 *
 * 202, never a finished file: 500 cards at 300 DPI run for minutes, and the API
 * container serves every other request single-process while it does. There is no
 * synchronous path even for one card — one renderer, one failure mode.
 */
export async function generateIdCards(
  orgId: string,
  eventId: string,
  payload: {
    id_card_template_id: string;
    recipients: Array<{ type: string; id: string }>;
  }
): Promise<{ batch_id: string; total: number }> {
  const { data } = await apiClient.post<ApiEnvelope<{ batch_id: string; total: number }>>(
    `/organizations/${orgId}/events/${eventId}/id-cards`,
    payload
  );
  return data.data;
}

export async function getIdCardBatch(orgId: string, batchId: string): Promise<IdCardBatch> {
  const { data } = await apiClient.get<ApiEnvelope<IdCardBatch>>(
    `/organizations/${orgId}/id-card-batches/${batchId}`
  );
  return data.data;
}

/**
 * Save the finished zip.
 *
 * Through apiClient with `responseType: "blob"`, never a plain `<a href>`: the
 * access token lives in memory, so a direct link to the API would 401. Same rule
 * as the exports and the billing documents.
 */
export async function downloadIdCardBatch(orgId: string, batchId: string): Promise<void> {
  const response = await apiClient.get<Blob>(
    `/organizations/${orgId}/id-card-batches/${batchId}/download`,
    { responseType: "blob" }
  );

  const fileName = fileNameFromDisposition(
    response.headers["content-disposition"],
    "id-cards.zip"
  );

  downloadBlob(response.data, fileName);
}

// ---- Issued cards ----

/** The print history. `meta.total` is what the send button reads. */
export async function getIssuedIdCards(
  orgId: string,
  params: { event_id?: string; page?: number; per_page?: number } = {}
): Promise<IdCardIssuedPage> {
  const { data } = await apiClient.get<ApiEnvelope<IdCardIssuedPage>>(
    `/organizations/${orgId}/id-cards/issued`,
    { params }
  );
  return data.data;
}

/**
 * Email an event's cards to the people they belong to.
 *
 * `cardIds` omitted means every card issued for the event. The server groups by
 * destination — one mail carries a whole team, because players and team
 * officials have no email of their own and borrow their manager's — so the
 * reply counts `emails` and `cards` separately, plus however many had no
 * address at all.
 */
export async function sendIdCards(
  orgId: string,
  eventId: string,
  cardIds?: string[]
): Promise<{ emails: number; cards: number; skipped: number }> {
  const { data } = await apiClient.post<
    ApiEnvelope<{ emails: number; cards: number; skipped: number }>
  >(
    `/organizations/${orgId}/events/${eventId}/id-cards/send`,
    cardIds ? { card_ids: cardIds } : {}
  );
  return data.data;
}

/** Save one card's PNG. Blob, never a plain `<a href>` — see the batch above. */
export async function downloadIdCard(orgId: string, cardId: string): Promise<void> {
  const response = await apiClient.get<Blob>(
    `/organizations/${orgId}/id-cards/${cardId}/download`,
    { responseType: "blob" }
  );

  const fileName = fileNameFromDisposition(
    response.headers["content-disposition"],
    "id-card.png"
  );

  downloadBlob(response.data, fileName);
}

/** What the QR printed on a card opens. Public — no token, nothing to install. */
export async function verifyIdCard(number: string): Promise<IdCardVerification> {
  const { data } = await apiClient.get<ApiEnvelope<IdCardVerification>>(
    `/public/id-cards/${encodeURIComponent(number)}`
  );
  return data.data;
}
