"use client";

import { useState } from "react";
import { Check, ExternalLink, FileText, ImageOff, Maximize2, Minimize2, ReceiptText, X } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { Textarea } from "@/components/ui/textarea";
import {
  Dialog,
  DialogBody,
  DialogContent,
  DialogFooter,
  DialogHeader,
  dialogConsequences,
} from "@/components/ui/dialog";

/** One "Bank · BCA" line under the proof. Values stay nodes so amounts can be bold. */
export type ProofDetail = { label: string; value: React.ReactNode };

/**
 * Extensions only — the URL is a signed object-storage link whose Content-Type
 * we never see, and a signature lives in the query string, so match the path.
 * A wrong guess is not fatal: <img onError> falls back to the same card a PDF
 * gets, which is why this can stay a list rather than a HEAD request.
 */
const IMAGE_PATH = /\.(jpe?g|png|gif|webp|avif|bmp|heic|heif)$/i;

/** "30 Jul 2026, 01.03" — the same shape every other dashboard queue prints. */
const fmtDateTime = (iso: string) =>
  new Date(iso).toLocaleString("id-ID", { dateStyle: "medium", timeStyle: "short" });

function looksLikeImage(url: string): boolean {
  try {
    return IMAGE_PATH.test(new URL(url).pathname);
  } catch {
    return IMAGE_PATH.test(url.split("?")[0]);
  }
}

/**
 * Look at a transfer receipt and rule on it, in one place.
 *
 * Both manual-payment queues used to hand the receipt to a new browser tab and
 * keep Terima/Tolak behind on the page — so the verdict was typed on a screen
 * that no longer showed the thing being judged. The proof and the two buttons
 * belong to one decision, so they belong in one dialog.
 *
 * Written against a plain proof URL rather than a Subscription/TicketOrder so
 * the super admin's plan queue and an organizer's event queue can share it;
 * everything model-specific arrives as `details`.
 */
export function PaymentProofDialog({
  open,
  onOpenChange,
  title,
  description,
  proofUrl,
  uploadedAt,
  details,
  consequence,
  rejectPlaceholder = "Alasan penolakan (dilihat pembayar)",
  busy = false,
  onApprove,
  onReject,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: string;
  description?: string;
  proofUrl: string | null;
  uploadedAt?: string | null;
  details: ProofDetail[];
  /** Why accepting is irreversible, shown above the buttons. */
  consequence: string;
  rejectPlaceholder?: string;
  busy?: boolean;
  onApprove: () => void;
  onReject: (reason: string) => void;
}) {
  const [mode, setMode] = useState<"view" | "reject">("view");
  const [reason, setReason] = useState("");
  const [zoomed, setZoomed] = useState(false);
  const [broken, setBroken] = useState(false);
  const [loaded, setLoaded] = useState(false);

  // A fresh row means a fresh receipt: without this, the previous proof's
  // loaded/broken state (and a half-typed rejection) would carry over. Reset
  // during render rather than in an effect — the React-documented shape, and
  // the one that avoids painting the new proof under the old state first.
  const [seenProof, setSeenProof] = useState(proofUrl);
  if (proofUrl !== seenProof) {
    setSeenProof(proofUrl);
    setMode("view");
    setReason("");
    setZoomed(false);
    setBroken(false);
    setLoaded(false);
  }

  const renderable = proofUrl !== null && looksLikeImage(proofUrl) && !broken;

  return (
    <Dialog open={open} onOpenChange={(next) => !busy && onOpenChange(next)}>
      <DialogContent className="max-w-2xl">
        <DialogHeader icon={ReceiptText} title={title} description={description} />

        <DialogBody>
          <div className="overflow-hidden rounded-lg border border-border bg-[var(--bg-soft)]">
            {proofUrl && renderable && (
              <div className={zoomed ? "max-h-[60vh] overflow-auto" : ""}>
                {!loaded && <Skeleton className="h-64 w-full rounded-none" />}
                {/* eslint-disable-next-line @next/next/no-img-element -- signed
                    object-storage URL on an unknown host; next/image would need
                    every bucket in remotePatterns. */}
                <img
                  src={proofUrl}
                  alt="Bukti transfer"
                  onLoad={() => setLoaded(true)}
                  onError={() => setBroken(true)}
                  className={
                    loaded
                      ? zoomed
                        ? "w-full max-w-none"
                        : "mx-auto max-h-[46vh] w-auto object-contain"
                      : "hidden"
                  }
                />
              </div>
            )}

            {proofUrl && !renderable && (
              <div className="flex flex-col items-center gap-2 p-8 text-center">
                {broken ? (
                  <ImageOff className="h-7 w-7 text-muted-foreground" />
                ) : (
                  <FileText className="h-7 w-7 text-muted-foreground" />
                )}
                <p className="text-sm font-semibold">
                  {broken ? "Gambar gagal dimuat" : "Bukti bukan berupa gambar"}
                </p>
                <p className="text-xs text-muted-foreground">
                  Buka berkasnya di tab baru untuk memeriksanya sebelum memutuskan.
                </p>
              </div>
            )}

            {!proofUrl && (
              <div className="flex flex-col items-center gap-2 p-8 text-center">
                <ImageOff className="h-7 w-7 text-muted-foreground" />
                <p className="text-sm font-semibold">Tidak ada bukti terlampir</p>
              </div>
            )}
          </div>

          {proofUrl && (
            <div className="flex flex-wrap items-center justify-between gap-2">
              <p className="text-xs text-muted-foreground">
                {uploadedAt
                  ? `Diunggah ${fmtDateTime(uploadedAt)}`
                  : "Waktu unggah tidak tercatat"}
              </p>
              <div className="flex items-center gap-1">
                {renderable && (
                  <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={() => setZoomed((z) => !z)}
                  >
                    {zoomed ? <Minimize2 className="h-4 w-4" /> : <Maximize2 className="h-4 w-4" />}
                    {zoomed ? "Perkecil" : "Perbesar"}
                  </Button>
                )}
                <Button asChild size="sm" variant="ghost">
                  <a href={proofUrl} target="_blank" rel="noopener noreferrer">
                    <ExternalLink className="h-4 w-4" />
                    Tab baru
                  </a>
                </Button>
              </div>
            </div>
          )}

          <dl className="grid gap-1.5 rounded-lg border border-border p-3 text-sm">
            {details.map((row, i) => (
              <div key={i} className="flex items-start justify-between gap-4">
                <dt className="text-muted-foreground">{row.label}</dt>
                <dd className="text-right font-medium">{row.value}</dd>
              </div>
            ))}
          </dl>

          {mode === "reject" ? (
            <div className="grid gap-2">
              <Textarea
                autoFocus
                value={reason}
                onChange={(e) => setReason(e.target.value)}
                placeholder={rejectPlaceholder}
                maxLength={500}
                className="min-h-[80px]"
              />
              <p className="text-xs text-muted-foreground">
                Alasannya ditampilkan ke pembayar, dan mereka bisa mengunggah bukti baru.
              </p>
            </div>
          ) : (
            <p className={dialogConsequences.default}>{consequence}</p>
          )}
        </DialogBody>

        <DialogFooter>
          {mode === "reject" ? (
            <>
              <Button
                type="button"
                variant="outline"
                disabled={busy}
                onClick={() => {
                  setMode("view");
                  setReason("");
                }}
              >
                Batal
              </Button>
              <Button
                type="button"
                variant="destructive"
                disabled={busy || reason.trim().length === 0}
                onClick={() => onReject(reason.trim())}
              >
                <X className="h-4 w-4" />
                {busy ? "Mengirim…" : "Kirim penolakan"}
              </Button>
            </>
          ) : (
            <>
              <Button
                type="button"
                variant="outline"
                disabled={busy}
                onClick={() => setMode("reject")}
              >
                <X className="h-4 w-4" />
                Tolak
              </Button>
              <Button type="button" disabled={busy} onClick={onApprove}>
                <Check className="h-4 w-4" />
                {busy ? "Memproses…" : "Terima pembayaran"}
              </Button>
            </>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
