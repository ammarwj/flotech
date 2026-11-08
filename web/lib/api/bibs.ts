import { apiClient } from "./client";
import { downloadBlob, fileNameFromDisposition } from "@/lib/download";
import type {
  ApiEnvelope,
  BibBatch,
  BibField,
  BibFieldDef,
  BibRecipient,
  BibTemplate,
} from "@/types/api";

// ---- Templates ----

/** The fields an e-BIB may place. Catalogued by config/bib.php. */
export async function getBibFields(orgId: string): Promise<BibFieldDef[]> {
  const { data } = await apiClient.get<ApiEnvelope<BibFieldDef[]>>(
    `/organizations/${orgId}/bib-fields`
  );
  return data.data;
}

export async function getBibTemplates(orgId: string): Promise<BibTemplate[]> {
  const { data } = await apiClient.get<ApiEnvelope<BibTemplate[]>>(
    `/organizations/${orgId}/bib-templates`
  );
  return data.data;
}

export interface BibTemplateInput {
  name: string;
  background_url: string;
  /** Millimetres. The presets never reach the API — they just write two numbers. */
  width_mm: number;
  height_mm: number;
  fields: BibField[];
}

export async function createBibTemplate(
  orgId: string,
  payload: BibTemplateInput
): Promise<BibTemplate> {
  const { data } = await apiClient.post<ApiEnvelope<BibTemplate>>(
    `/organizations/${orgId}/bib-templates`,
    payload
  );
  return data.data;
}

export async function updateBibTemplate(
  orgId: string,
  templateId: string,
  payload: Partial<BibTemplateInput>
): Promise<BibTemplate> {
  const { data } = await apiClient.patch<ApiEnvelope<BibTemplate>>(
    `/organizations/${orgId}/bib-templates/${templateId}`,
    payload
  );
  return data.data;
}

export async function deleteBibTemplate(orgId: string, templateId: string): Promise<void> {
  await apiClient.delete(`/organizations/${orgId}/bib-templates/${templateId}`);
}

// ---- Batches ----

/** Every PAID runner in the event — see BibRecipient for why only those. */
export async function getBibRecipients(orgId: string, eventId: string): Promise<BibRecipient[]> {
  const { data } = await apiClient.get<ApiEnvelope<BibRecipient[]>>(
    `/organizations/${orgId}/events/${eventId}/bib-recipients`
  );
  return data.data;
}

/**
 * Queue a batch and get back the id to poll.
 *
 * 202, never a finished file: an A5 sheet at 300 DPI is 2480x1748, and a full
 * field runs for minutes while the API container serves every other request
 * single-process. There is no synchronous path even for one runner.
 *
 * `numbered` in the reply is how many race numbers this call assigned — the
 * server numbers the field before rendering, so an organizer never prints a
 * sheet with a blank number on it. It is stated because the start list they are
 * looking at just changed.
 */
export async function generateBibs(
  orgId: string,
  eventId: string,
  payload: { bib_template_id: string; entry_ids: string[] }
): Promise<{ batch_id: string; total: number; numbered: number }> {
  const { data } = await apiClient.post<
    ApiEnvelope<{ batch_id: string; total: number; numbered: number }>
  >(`/organizations/${orgId}/events/${eventId}/bibs`, payload);
  return data.data;
}

export async function getBibBatch(orgId: string, batchId: string): Promise<BibBatch> {
  const { data } = await apiClient.get<ApiEnvelope<BibBatch>>(
    `/organizations/${orgId}/bib-batches/${batchId}`
  );
  return data.data;
}

/**
 * Save the finished zip.
 *
 * Through apiClient with `responseType: "blob"`, never a plain `<a href>`: the
 * access token lives in memory, so a direct link to the API would 401. Same
 * rule as the exports and the billing documents.
 */
export async function downloadBibBatch(orgId: string, batchId: string): Promise<void> {
  const response = await apiClient.get<Blob>(
    `/organizations/${orgId}/bib-batches/${batchId}/download`,
    { responseType: "blob" }
  );

  const fileName = fileNameFromDisposition(response.headers["content-disposition"], "e-bib.zip");

  downloadBlob(response.data, fileName);
}

/**
 * Email an event's e-BIBs to the runners they belong to.
 *
 * `entryIds` omitted means every sheet rendered for the event. One mail per
 * runner — a runner holds their own address, unlike a player who borrows their
 * team manager's — so the reply counts `emails` plus however many had no
 * address at all.
 */
export async function sendBibs(
  orgId: string,
  eventId: string,
  entryIds?: string[]
): Promise<{ emails: number; skipped: number }> {
  const { data } = await apiClient.post<ApiEnvelope<{ emails: number; skipped: number }>>(
    `/organizations/${orgId}/events/${eventId}/bibs/send`,
    entryIds ? { entry_ids: entryIds } : {}
  );
  return data.data;
}

/** Save one runner's sheet. Blob, never a plain `<a href>` — see the batch above. */
export async function downloadBib(
  orgId: string,
  eventId: string,
  entryId: string
): Promise<void> {
  const response = await apiClient.get<Blob>(
    `/organizations/${orgId}/events/${eventId}/bibs/${entryId}/download`,
    { responseType: "blob" }
  );

  const fileName = fileNameFromDisposition(response.headers["content-disposition"], "e-bib.png");

  downloadBlob(response.data, fileName);
}

/**
 * The runner's own copy, from their proof page.
 *
 * A separate function from the organizer's download above rather than a shared
 * one with a flag: the two hit different routes for different reasons — this
 * one is public, behind the entry's unguessable id, because a runner may have
 * arrived from an email and may never have signed up.
 */
export async function downloadMyBib(entryId: string): Promise<void> {
  const response = await apiClient.get<Blob>(`/race-entries/${entryId}/bib`, {
    responseType: "blob",
  });

  const fileName = fileNameFromDisposition(response.headers["content-disposition"], "e-bib.png");

  downloadBlob(response.data, fileName);
}
