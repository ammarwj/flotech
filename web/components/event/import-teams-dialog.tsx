"use client";

import { useRef, useState } from "react";
import { UploadCloud, FileSpreadsheet, AlertCircle } from "lucide-react";
import { toast } from "sonner";

import type { EventCategory } from "@/types/api";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { parseApiError } from "@/lib/api/errors";
import {
  downloadRegistrationTemplate,
  importRegistrations,
  type ImportResult,
} from "@/lib/api/imports";
import { participantLabel } from "@/lib/scoring";

/**
 * Bulk-import teams into one category from a filled-in Excel template.
 *
 * Scoped to a single category on purpose — the template's shape (single vs.
 * double vs. team roster) depends on it, same reason ManualTeamDialog derives
 * `isFixed` from the chosen category. Row-level failures don't close the
 * dialog: the organizer needs to see which rows failed before re-uploading a
 * corrected file.
 */
export function ImportTeamsDialog({
  open,
  categories,
  orgId,
  eventId,
  onClose,
  onImported,
}: {
  open: boolean;
  categories: EventCategory[];
  orgId: string;
  eventId: string;
  onClose: () => void;
  onImported: () => void;
}) {
  const [categoryId, setCategoryId] = useState<string>(categories[0]?.id ?? "");
  const resolvedCategoryId = categoryId || categories[0]?.id || "";
  const [file, setFile] = useState<File | null>(null);
  const [downloading, setDownloading] = useState(false);
  const [importing, setImporting] = useState(false);
  const [result, setResult] = useState<ImportResult | null>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);

  if (!open) return null;

  const reset = () => {
    setFile(null);
    setResult(null);
    if (fileInputRef.current) fileInputRef.current.value = "";
  };

  const close = () => {
    reset();
    onClose();
  };

  const download = async () => {
    if (!resolvedCategoryId) return;
    setDownloading(true);
    try {
      await downloadRegistrationTemplate(orgId, eventId, resolvedCategoryId);
    } catch {
      toast.error("Gagal mengunduh template.");
    } finally {
      setDownloading(false);
    }
  };

  const submit = async () => {
    if (!resolvedCategoryId || !file) return;
    setImporting(true);
    setResult(null);
    try {
      const res = await importRegistrations(orgId, eventId, resolvedCategoryId, file);
      setResult(res);
      if (res.created > 0) {
        toast.success(`${res.created} tim berhasil diimpor.`);
        onImported();
      }
      if (res.errors.length === 0) {
        close();
      } else {
        // Leave the dialog open so the organizer can read which rows failed
        // and re-upload a corrected file — a toast can't hold a row list.
        if (fileInputRef.current) fileInputRef.current.value = "";
        setFile(null);
      }
    } catch (err) {
      const { message } = parseApiError(err, "Gagal mengimpor file.");
      toast.error(message);
    } finally {
      setImporting(false);
    }
  };

  return (
    <div
      className="fixed inset-0 z-50 grid place-items-center bg-black/50 p-4"
      role="dialog"
      aria-modal="true"
      aria-label="Import tim dari Excel"
    >
      <div className="max-h-[85vh] w-full max-w-lg overflow-y-auto rounded-xl border border-border bg-[var(--surface)] shadow-[var(--shadow-lg)]">
        <div className="border-b border-border p-4">
          <h2 className="text-base font-bold" style={{ fontFamily: "var(--font-display)" }}>
            Import tim dari Excel
          </h2>
          <p className="mt-1 text-sm text-muted-foreground">
            Unduh template sesuai kategori, isi, lalu unggah kembali. Tim yang masuk
            langsung disetujui dan dianggap lunas, sama seperti entri manual.
          </p>
        </div>

        <div className="grid gap-4 p-4">
          <div className="grid gap-2">
            <Label htmlFor="import-category" className="font-semibold">
              Kategori<span className="text-[var(--danger)]"> *</span>
            </Label>
            <Select
              id="import-category"
              value={resolvedCategoryId}
              onChange={(e) => {
                setCategoryId(e.target.value);
                reset();
              }}
            >
              {categories.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name} · {participantLabel(c.participant_type)}
                </option>
              ))}
            </Select>
          </div>

          <Button
            variant="outline"
            onClick={download}
            disabled={!resolvedCategoryId || downloading}
            className="justify-start"
          >
            <FileSpreadsheet className="h-4 w-4" />
            {downloading ? "Mengunduh…" : "Unduh Template"}
          </Button>

          <div className="grid gap-2">
            <Label htmlFor="import-file" className="font-semibold">
              File terisi (.xlsx)
            </Label>
            <input
              ref={fileInputRef}
              id="import-file"
              type="file"
              accept=".xlsx,.xls"
              onChange={(e) => setFile(e.target.files?.[0] ?? null)}
              className="text-sm file:mr-3 file:rounded-md file:border-0 file:bg-[var(--tint)] file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-[var(--brand-600)]"
            />
          </div>

          <p className="text-xs text-muted-foreground">
            Field custom dan dokumen tidak bisa lewat import — lengkapi manual lewat
            tombol &quot;Ubah&quot; setelah tim masuk.
          </p>

          {result && result.errors.length > 0 && (
            <div className="grid gap-2 rounded-lg border border-[var(--danger)]/30 bg-[var(--danger)]/5 p-3">
              <p className="flex items-center gap-1.5 text-sm font-semibold text-[var(--danger)]">
                <AlertCircle className="h-4 w-4" />
                {result.created} tim berhasil, {result.errors.length} baris gagal
              </p>
              <ul className="grid gap-1 text-xs text-muted-foreground">
                {result.errors.map((e, i) => (
                  <li key={i}>
                    Baris {e.row}: {e.message}
                  </li>
                ))}
              </ul>
            </div>
          )}
        </div>

        <div className="flex flex-wrap items-center justify-end gap-2 border-t border-border p-4">
          <Button variant="ghost" onClick={close} disabled={importing}>
            {result ? "Tutup" : "Batal"}
          </Button>
          <Button onClick={submit} disabled={!resolvedCategoryId || !file || importing}>
            <UploadCloud className="h-4 w-4" />
            {importing ? "Mengimpor…" : "Import"}
          </Button>
        </div>
      </div>
    </div>
  );
}
