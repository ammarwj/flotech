/** Save a blob to disk under `fileName`. */
export function downloadBlob(blob: Blob, fileName: string): void {
  const url = URL.createObjectURL(blob);

  const a = document.createElement("a");
  a.href = url;
  a.download = fileName;
  a.click();

  URL.revokeObjectURL(url);
}

/**
 * Re-parse an error body that arrived as a Blob, in place.
 *
 * `responseType: "blob"` applies to the error response too, so a 422 comes back
 * as a Blob and `parseApiError()` reads straight past it to the generic
 * fallback. That matters most where the refusal *is* the feature — the lineup
 * sheet's "susunan pemain kedua tim harus disetujui wasit dulu" is the one
 * sentence that says what is missing, and swallowing it leaves a button that
 * fails with "coba lagi" however many times it is pressed.
 *
 * Rewritten in place rather than returned as a message, so callers keep using
 * the same `parseApiError()` they use everywhere else.
 */
export async function unpackBlobError(err: unknown): Promise<unknown> {
  const body = (err as { response?: { data?: unknown } })?.response?.data;

  if (!(body instanceof Blob)) return err;

  try {
    (err as { response: { data: unknown } }).response.data = JSON.parse(await body.text());
  } catch {
    // A blob that isn't JSON — a gateway's HTML error page, say. Leave it be;
    // the fallback message is the honest answer for that one.
  }

  return err;
}

/**
 * Pull the filename out of a `Content-Disposition` header, falling back to
 * `fallback` when the server didn't send one.
 */
export function fileNameFromDisposition(disposition: unknown, fallback: string): string {
  if (typeof disposition !== "string") return fallback;

  const match = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(disposition);
  return match ? decodeURIComponent(match[1]) : fallback;
}
