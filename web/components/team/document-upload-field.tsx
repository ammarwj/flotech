"use client";

import { useRef, useState } from "react";
import { FileText, Loader2, Upload, X } from "lucide-react";
import { toast } from "sonner";

import { uploadDocument } from "@/lib/api/events";
import {
  acceptAttr,
  acceptLabel,
  docFor,
  putDoc,
  type DocumentRow,
  type DocumentSlot,
} from "@/lib/registration-form";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";

/**
 * The document slots an event asks for, one row per type.
 *
 * A slot holds exactly one file: uploading again replaces what was there, which
 * is why the old row's id goes with it — the server then prunes the old file
 * instead of keeping two answers to one question.
 *
 * Renders nothing at all when the event defines no documents. That is the whole
 * of "no document types ⇒ no upload UI": there is no empty state to suppress,
 * because an empty list produces no elements.
 */
export function DocumentUploadFields({
  slots,
  value,
  onChange,
  onBusyChange,
  disabled,
  label,
}: {
  slots: DocumentSlot[];
  value: DocumentRow[];
  onChange: (rows: DocumentRow[]) => void;
  /** Lets the form disable Save while an upload is in flight. */
  onBusyChange?: (busy: boolean) => void;
  disabled?: boolean;
  /** Section heading. Omit inside a player row, where the context is already clear. */
  label?: string;
}) {
  if (slots.length === 0) return null;

  return (
    <div className="grid gap-3">
      {label && <Label>{label}</Label>}
      {slots.map((slot) => (
        <DocumentSlotRow
          key={slot.key}
          slot={slot}
          row={docFor(value, slot.key)}
          onChange={(row) => onChange(putDoc(value, slot.key, row))}
          onBusyChange={onBusyChange}
          disabled={disabled}
        />
      ))}
    </div>
  );
}

function DocumentSlotRow({
  slot,
  row,
  onChange,
  onBusyChange,
  disabled,
}: {
  slot: DocumentSlot;
  row?: DocumentRow;
  onChange: (row: DocumentRow | null) => void;
  onBusyChange?: (busy: boolean) => void;
  disabled?: boolean;
}) {
  const [uploading, setUploading] = useState(false);
  const inputRef = useRef<HTMLInputElement>(null);

  const setBusy = (busy: boolean) => {
    setUploading(busy);
    onBusyChange?.(busy);
  };

  const pick = async (file?: File | null) => {
    if (!file) return;
    // The API checks both again — this only spares the user a round trip.
    if (file.size > 5 * 1024 * 1024) {
      toast.error("Ukuran berkas maksimal 5 MB.");
      return;
    }

    setBusy(true);
    try {
      const { file_url, file_name } = await uploadDocument(file);
      onChange({ file_url, file_name });
    } catch {
      toast.error(`Gagal mengunggah ${slot.label}. Coba lagi.`);
    } finally {
      setBusy(false);
      if (inputRef.current) inputRef.current.value = "";
    }
  };

  const clear = () => {
    onChange(null);
    if (inputRef.current) inputRef.current.value = "";
  };

  return (
    <div className="rounded-xl border border-border p-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="min-w-0">
          <p className="flex items-center gap-2 text-sm font-medium">
            {slot.label}
            {slot.required ? (
              <Badge variant="warning">Wajib</Badge>
            ) : (
              <span className="text-xs font-normal text-muted-foreground">Opsional</span>
            )}
          </p>
          {row ? (
            <a
              href={row.file_url}
              target="_blank"
              rel="noreferrer"
              className="mt-1 flex items-center gap-1.5 truncate text-xs text-primary hover:underline"
            >
              <FileText className="h-3.5 w-3.5 shrink-0" />
              {row.file_name || "Lihat berkas"}
            </a>
          ) : (
            <p className="mt-1 text-xs text-muted-foreground">
              Belum ada berkas · {acceptLabel(slot)}, maks 5 MB
            </p>
          )}
        </div>

        <div className="flex shrink-0 items-center gap-1">
          <Button
            type="button"
            variant="outline"
            size="sm"
            disabled={disabled || uploading}
            onClick={() => inputRef.current?.click()}
          >
            {uploading ? (
              <Loader2 className="h-4 w-4 animate-spin" />
            ) : (
              <Upload className="h-4 w-4" />
            )}
            {row ? "Ganti" : "Unggah"}
          </Button>
          {row && (
            <Button
              type="button"
              variant="ghost"
              size="sm"
              disabled={disabled || uploading}
              onClick={clear}
            >
              <X className="h-4 w-4" />
            </Button>
          )}
        </div>
      </div>

      <input
        ref={inputRef}
        type="file"
        accept={acceptAttr(slot)}
        className="hidden"
        onChange={(e) => pick(e.target.files?.[0])}
      />
    </div>
  );
}
