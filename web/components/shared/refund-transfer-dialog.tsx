"use client";

import { useEffect, useState } from "react";
import { CheckCircle2, Copy, X } from "lucide-react";
import { toast } from "sonner";

import type { RefundPayout } from "@/types/api";
import { uploadImage } from "@/lib/api/events";
import { compressToWebp } from "@/lib/image";
import { rupiah } from "@/lib/labels";
import { payoutLabels } from "@/lib/payout";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

/**
 * Records a refund transfer that already happened in somebody's banking app —
 * this button moves no money by itself. The proof is mandatory for the same
 * reason the payout dialog's is: a transfer marked done with nothing behind it
 * is unauditable, and here a second person downstream is waiting on it.
 *
 * ONE dialog for both hops, picked by `hop`, because the two differ only in who
 * is paying whom and which column the proof lands in. Two components would be
 * two copies of the upload path, and the drift would show up as one hop
 * accepting a receipt the other rejects.
 *
 * The destination panel is shown only for `platform`: hop two is the organizer
 * paying their own participant, whose account the platform has never held — so
 * printing an account there would be printing the organizer's own number back
 * at them as if it were the recipient's.
 */
export function RefundTransferDialog({
  payout,
  hop,
  pending,
  onClose,
  onSubmit,
}: {
  payout: RefundPayout | null;
  hop: "platform" | "organizer";
  pending: boolean;
  onClose: () => void;
  onSubmit: (values: { proofUrl: string; reference: string | null }) => void;
}) {
  const [proofUrl, setProofUrl] = useState("");
  const [reference, setReference] = useState("");
  const [uploading, setUploading] = useState(false);

  // The parent keys this by payout id, so each row opens with a fresh form
  // rather than the previous one's proof.
  useEffect(() => {
    if (!payout) return;
    const onKey = (e: KeyboardEvent) => e.key === "Escape" && onClose();
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [payout, onClose]);

  if (!payout) return null;

  const toPlatform = hop === "platform";
  const labels = payoutLabels(payout.account_type);

  const copy = (text: string) => {
    navigator.clipboard.writeText(text).then(
      () => toast.success("Disalin"),
      () => toast.error("Gagal menyalin.")
    );
  };

  const onFile = async (file: File) => {
    setUploading(true);
    try {
      // Same 1600/0.85 as every other transfer receipt: a camera shot of an
      // m-banking screen is text, and uncompressed it hits the endpoint's cap.
      const webp = await compressToWebp(file, { maxDim: 1600, quality: 0.85 });
      setProofUrl(await uploadImage(webp, "refund-proofs"));
      toast.success("Bukti transfer terunggah");
    } catch {
      toast.error("Gagal mengunggah bukti transfer.");
    } finally {
      setUploading(false);
    }
  };

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
      onClick={onClose}
    >
      <div
        role="dialog"
        aria-modal="true"
        onClick={(e) => e.stopPropagation()}
        className="w-full max-w-lg overflow-hidden rounded-xl border border-border bg-card shadow-[var(--shadow-lg)]"
      >
        <div className="flex items-start gap-3 border-b border-border p-5">
          <span className="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-[var(--tint)] text-[var(--brand-600)]">
            <CheckCircle2 className="h-5 w-5" />
          </span>
          <div className="min-w-0 flex-1">
            <h2 className="text-base font-bold" style={{ fontFamily: "var(--font-display)" }}>
              {toPlatform ? "Tandai Sudah Ditransfer" : "Tandai Sudah Diteruskan"}
            </h2>
            <p className="mt-0.5 text-sm text-muted-foreground">
              {toPlatform
                ? "Transfer dulu ke rekening penyelenggara, baru catat buktinya di sini."
                : "Transfer dulu ke peserta, baru catat buktinya di sini."}
            </p>
          </div>
          <button
            onClick={onClose}
            className="rounded-md p-1 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
            aria-label="Tutup"
          >
            <X className="h-4 w-4" />
          </button>
        </div>

        <div className="grid gap-4 p-5">
          <div className="grid gap-1.5 rounded-lg border border-border bg-[var(--bg-alt)] p-3 text-sm">
            <div className="flex items-center justify-between gap-2">
              <span className="text-muted-foreground">Transfer sejumlah</span>
              <span className="font-bold tabular-nums">{rupiah(payout.amount)}</span>
            </div>
            <div className="flex items-center justify-between gap-2">
              <span className="text-muted-foreground">Refund untuk</span>
              <span className="font-semibold">{payout.recipient_name}</span>
            </div>

            {toPlatform &&
              (payout.account_number ? (
                <>
                  <div className="flex items-center justify-between gap-2">
                    <span className="text-muted-foreground">
                      {labels.kind} {payout.bank_name}
                    </span>
                    <button
                      onClick={() => copy(payout.account_number!)}
                      className="inline-flex items-center gap-1.5 font-mono font-semibold transition-colors hover:text-[var(--brand-600)]"
                    >
                      {payout.account_number}
                      <Copy className="h-3.5 w-3.5" />
                    </button>
                  </div>
                  <div className="flex items-center justify-between gap-2">
                    <span className="text-muted-foreground">Atas nama</span>
                    <span className="font-semibold">{payout.account_holder}</span>
                  </div>
                </>
              ) : (
                <p className="text-sm text-destructive">
                  Penyelenggara ini belum mengisi rekening utama, jadi transfernya belum bisa
                  dijalankan. Hubungi mereka lebih dulu.
                </p>
              ))}
          </div>

          {!toPlatform && (
            <p className="rounded-lg border border-border bg-[var(--bg-alt)] p-3 text-sm text-muted-foreground">
              Rekening peserta tidak kami simpan — pakai data yang pesertanya berikan ke Anda.
              Setelah ditandai, pesertanya bisa melihat statusnya di halaman pendaftarannya.
            </p>
          )}

          <div className="grid gap-1.5">
            <Label htmlFor="refund-proof">Bukti transfer</Label>
            <Input
              id="refund-proof"
              type="file"
              accept="image/*"
              disabled={uploading}
              onChange={(e) => {
                const file = e.target.files?.[0];
                if (file) void onFile(file);
              }}
            />
            {uploading && <p className="text-xs text-muted-foreground">Mengunggah…</p>}
            {/* The upload shown in place, not behind a link to a new tab: this
                is the one moment the uploader can still catch a photo of the
                wrong screen, and a tab that replaces the half-filled form is
                the worst possible time to leave it. */}
            {proofUrl && (
              // eslint-disable-next-line @next/next/no-img-element
              <img
                src={proofUrl}
                alt="Bukti transfer yang diunggah"
                className="max-h-48 w-full rounded-lg border border-border bg-[var(--bg-alt)] object-contain"
              />
            )}
          </div>

          <div className="grid gap-1.5">
            <Label htmlFor="refund-reference">Nomor referensi bank (opsional)</Label>
            <Input
              id="refund-reference"
              value={reference}
              onChange={(e) => setReference(e.target.value)}
              placeholder="Mis. TRX-000123"
            />
          </div>
        </div>

        <div className="flex justify-end gap-2 border-t border-border p-5">
          <Button variant="ghost" onClick={onClose}>
            Batal
          </Button>
          <Button
            disabled={!proofUrl || pending || uploading}
            onClick={() => onSubmit({ proofUrl, reference: reference || null })}
          >
            {pending ? "Menyimpan…" : toPlatform ? "Tandai ditransfer" : "Tandai diteruskan"}
          </Button>
        </div>
      </div>
    </div>
  );
}
