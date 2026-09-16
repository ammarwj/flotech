import { apiClient } from "./client";
import { downloadBlob, fileNameFromDisposition } from "@/lib/download";
import type {
  ApiEnvelope,
  IdCardBatch,
  IdCardField,
  IdCardFieldDef,
  IdCardRecipient,
  IdCardTemplate,
} from "@/types/api";

// ---- Templates ----

/** The fields a card may place. Catalogued by config/id_card.php. */
export async function getIdCardFields(orgId: string): Promise<IdCardFieldDef[]> {
  const { data } = await apiClient.get<ApiEnvelope<IdCardFieldDef[]>>(
    `/organizations/${orgId}/id-card-fields`
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
