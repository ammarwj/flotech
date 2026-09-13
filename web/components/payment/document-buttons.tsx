"use client";

import { useState } from "react";
import { useMutation } from "@tanstack/react-query";
import { toast } from "sonner";
import { Eye } from "lucide-react";

import {
  getParticipantDocument,
  type DocumentKind,
  type DocumentSubject,
} from "@/lib/api/participant-documents";
import { Button } from "@/components/ui/button";
import {
  DocumentPreviewDialog,
  type PreviewDocument,
} from "@/components/subscription/document-preview-dialog";

/**
 * "Invoice" and "Kwitansi" for a participant's own payment, with the preview
 * dialog wired up.
 *
 * One component for every surface — the public e-ticket page, a team's own
 * page, and the organizer's buyer/registration lists — because the pair of
 * buttons, the blob fetch and the object-URL lifetime are identical, and the
 * lifetime is the part worth not writing twice: whoever creates the URL has to
 * revoke it. Which endpoint gets called is the `subject`'s business.
 *
 * `hasReceipt` gates the second button rather than a `paid` flag: a free order
 * is settled but never issued a receipt, and asking about payment status would
 * offer a button that 404s.
 */
export function ParticipantDocumentButtons({
  subject,
  hasInvoice,
  hasReceipt,
}: {
  subject: DocumentSubject;
  hasInvoice: boolean;
  hasReceipt: boolean;
}) {
  const [preview, setPreview] = useState<PreviewDocument | null>(null);
  const [busy, setBusy] = useState<DocumentKind | null>(null);

  const closePreview = () => {
    setPreview((current) => {
      if (current) URL.revokeObjectURL(current.url);
      return null;
    });
  };

  const open = useMutation({
    mutationFn: async (document: DocumentKind) => {
      const { blob, fileName } = await getParticipantDocument(subject, document);
      return {
        title: document === "receipt" ? "Kwitansi" : "Invoice",
        fileName,
        blob,
        url: URL.createObjectURL(blob),
      };
    },
    onSuccess: (doc) => setPreview(doc),
    onError: () => toast.error("Gagal memuat dokumen."),
    onSettled: () => setBusy(null),
  });

  if (!hasInvoice && !hasReceipt) return null;

  return (
    <>
      <div className="flex flex-wrap gap-2">
        {hasInvoice && (
          <Button
            variant="outline"
            size="sm"
            disabled={busy !== null}
            onClick={() => {
              setBusy("invoice");
              open.mutate("invoice");
            }}
          >
            <Eye className="h-4 w-4" />
            Invoice
          </Button>
        )}
        {hasReceipt && (
          <Button
            variant="outline"
            size="sm"
            disabled={busy !== null}
            onClick={() => {
              setBusy("receipt");
              open.mutate("receipt");
            }}
          >
            <Eye className="h-4 w-4" />
            Kwitansi
          </Button>
        )}
      </div>

      <DocumentPreviewDialog document={preview} onClose={closePreview} />
    </>
  );
}
