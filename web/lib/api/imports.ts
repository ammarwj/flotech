import { apiClient } from "./client";
import { downloadBlob, fileNameFromDisposition } from "@/lib/download";

/**
 * Download the per-category bulk-import template.
 *
 * Same reason as downloadExport(): goes through apiClient with
 * `responseType: "blob"`, never a plain `<a href>` — the access token lives
 * in memory only.
 */
export async function downloadRegistrationTemplate(
  orgId: string,
  eventId: string,
  categoryId: string
): Promise<void> {
  const response = await apiClient.get<Blob>(
    `/organizations/${orgId}/events/${eventId}/registrations/import-template`,
    { params: { category_id: categoryId }, responseType: "blob" }
  );

  const fileName = fileNameFromDisposition(
    response.headers["content-disposition"],
    "template.xlsx"
  );

  downloadBlob(response.data, fileName);
}

export type ImportRowError = { row: number; message: string };
export type ImportResult = { created: number; errors: ImportRowError[] };

/** Upload a filled-in template. Row-level failures come back as `errors`, not a 4xx. */
export async function importRegistrations(
  orgId: string,
  eventId: string,
  categoryId: string,
  file: File
): Promise<ImportResult> {
  const form = new FormData();
  form.append("category_id", categoryId);
  form.append("file", file);

  const response = await apiClient.post<{ data: ImportResult }>(
    `/organizations/${orgId}/events/${eventId}/registrations/import`,
    form
  );

  return response.data.data;
}
