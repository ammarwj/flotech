/**
 * CSV export that opens cleanly in Excel.
 *
 * Two details make that work: a UTF-8 BOM (so accented names don't turn into
 * mojibake) and a leading `sep=;` line, which tells Excel to split on semicolons
 * regardless of the machine's locale — an Indonesian Excel would otherwise keep
 * a comma-separated file in a single column.
 */
export function toCsv(headers: string[], rows: (string | number | null | undefined)[][]): string {
  const cell = (v: string | number | null | undefined) => {
    const s = v === null || v === undefined ? "" : String(v);
    return /[";\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
  };

  const lines = [headers.map(cell).join(";"), ...rows.map((r) => r.map(cell).join(";"))];

  return `sep=;\n${lines.join("\n")}`;
}

/** Trigger a download of `content` as a spreadsheet-friendly CSV file. */
export function downloadCsv(fileName: string, content: string): void {
  const blob = new Blob([`﻿${content}`], { type: "text/csv;charset=utf-8;" });
  const url = URL.createObjectURL(blob);

  const a = document.createElement("a");
  a.href = url;
  a.download = fileName.endsWith(".csv") ? fileName : `${fileName}.csv`;
  a.click();

  URL.revokeObjectURL(url);
}

/** "Statistik Pemain — RING OF JOGJA" → "statistik-pemain-ring-of-jogja". */
export function slugifyFileName(text: string): string {
  return text
    .toLowerCase()
    .normalize("NFKD")
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "");
}
