"use client";

import { useEffect } from "react";
import { Download, FileText, X } from "lucide-react";

import { downloadBlob } from "@/lib/download";
import { Button } from "@/components/ui/button";

export interface PreviewDocument {
  title: string;
  fileName: string;
  blob: Blob;
  /** Object URL for `blob`; the opener owns it and revokes it on close. */
  url: string;
}

/**
 * Shows a billing PDF inline before the organizer commits to saving it.
 *
 * The PDF is already in hand as a blob (it has to be — the API needs the
 * bearer token), so the preview is just an object URL in an iframe and the
 * download button reuses the very same bytes. No second request.
 */
export function DocumentPreviewDialog({
  document: doc,
  onClose,
}: {
  document: PreviewDocument | null;
  onClose: () => void;
}) {
  useEffect(() => {
    if (!doc) return;
    const onKey = (e: KeyboardEvent) => e.key === "Escape" && onClose();
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [doc, onClose]);

  if (!doc) return null;

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
      onClick={onClose}
    >
      <div
        role="dialog"
        aria-modal="true"
        aria-label={doc.title}
        onClick={(e) => e.stopPropagation()}
        className="flex h-full max-h-[90vh] w-full max-w-3xl flex-col overflow-hidden rounded-xl border border-border bg-card shadow-[var(--shadow-lg)]"
      >
        <div className="flex items-start gap-3 border-b border-border p-5">
          <span className="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-[var(--tint)] text-[var(--brand-600)]">
            <FileText className="h-5 w-5" />
          </span>
          <div className="min-w-0 flex-1">
            <h2 className="text-base font-bold" style={{ fontFamily: "var(--font-display)" }}>
              {doc.title}
            </h2>
            <p className="mt-0.5 truncate text-sm text-muted-foreground">{doc.fileName}</p>
          </div>
          <button
            onClick={onClose}
            className="rounded-md p-1 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
            aria-label="Tutup"
          >
            <X className="h-4 w-4" />
          </button>
        </div>

        <iframe
          src={doc.url}
          title={doc.title}
          className="min-h-0 flex-1 bg-[var(--bg-alt)]"
        />

        <div className="flex justify-end gap-2 border-t border-border p-5">
          <Button variant="ghost" onClick={onClose}>
            Tutup
          </Button>
          <Button onClick={() => downloadBlob(doc.blob, doc.fileName)}>
            <Download className="h-4 w-4" />
            Unduh PDF
          </Button>
        </div>
      </div>
    </div>
  );
}
