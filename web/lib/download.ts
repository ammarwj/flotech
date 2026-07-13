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
 * Pull the filename out of a `Content-Disposition` header, falling back to
 * `fallback` when the server didn't send one.
 */
export function fileNameFromDisposition(disposition: unknown, fallback: string): string {
  if (typeof disposition !== "string") return fallback;

  const match = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(disposition);
  return match ? decodeURIComponent(match[1]) : fallback;
}
