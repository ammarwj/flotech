import { apiClient } from "./client";
import { downloadBlob, fileNameFromDisposition } from "@/lib/download";

/** What can be exported. Mirrors ExportController's two kind lists. */
export type ExportKind = "registrations" | "ticket-buyers" | "standings" | "leaderboard";
export type ExportFormat = "xlsx" | "pdf";

/** These are per-category, so they need `categoryId`. */
export const CATEGORY_EXPORTS: ExportKind[] = ["standings", "leaderboard"];

/**
 * Download an event export.
 *
 * Goes through apiClient with `responseType: "blob"`, never a plain `<a href>`:
 * the access token lives in memory, so a direct link to the API would 401.
 * Same rule as the billing documents.
 */
export async function downloadExport(
  orgId: string,
  eventId: string,
  kind: ExportKind,
  format: ExportFormat,
  categoryId?: string
): Promise<void> {
  const response = await apiClient.get<Blob>(
    `/organizations/${orgId}/events/${eventId}/exports/${kind}`,
    { params: { format, category_id: categoryId }, responseType: "blob" }
  );

  const fileName = fileNameFromDisposition(
    response.headers["content-disposition"],
    `${kind}.${format}`
  );

  downloadBlob(response.data, fileName);
}
