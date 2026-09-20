import { apiClient } from "./client";
import { downloadBlob, fileNameFromDisposition } from "@/lib/download";

/**
 * Printable player album — one team's roster (or every approved team in the
 * event) as a PDF, one team per page. Same blob-download shape as exports.ts:
 * apiClient + responseType "blob", never a plain <a href> (token is in-memory).
 */
export async function downloadTeamAlbum(
  orgId: string,
  eventId: string,
  teamId: string
): Promise<void> {
  const response = await apiClient.get<Blob>(
    `/organizations/${orgId}/events/${eventId}/registrations/${teamId}/album`,
    { responseType: "blob" }
  );

  const fileName = fileNameFromDisposition(response.headers["content-disposition"], "album.pdf");

  downloadBlob(response.data, fileName);
}

export async function downloadEventAlbum(orgId: string, eventId: string): Promise<void> {
  const response = await apiClient.get<Blob>(
    `/organizations/${orgId}/events/${eventId}/registrations/album`,
    { responseType: "blob" }
  );

  const fileName = fileNameFromDisposition(response.headers["content-disposition"], "album.pdf");

  downloadBlob(response.data, fileName);
}

/** A team manager's own album for their team, scoped by session instead of org/event. */
export async function downloadMyTeamAlbum(teamId: string): Promise<void> {
  const response = await apiClient.get<Blob>(`/my-teams/${teamId}/album`, {
    responseType: "blob",
  });

  const fileName = fileNameFromDisposition(response.headers["content-disposition"], "album.pdf");

  downloadBlob(response.data, fileName);
}
