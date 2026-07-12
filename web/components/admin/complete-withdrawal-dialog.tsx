"use client";

import { useEffect, useState } from "react";
import { CheckCircle2, Copy, X } from "lucide-react";
import { toast } from "sonner";

import type { CompleteWithdrawalInput } from "@/lib/api/admin-wallet";
import type { Withdrawal } from "@/types/api";
import { uploadImage } from "@/lib/api/events";
import { rupiah } from "@/lib/labels";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

/**
 * Records a transfer the admin already made in their banking app — this button
 * moves no money by itself. The receipt is mandatory: a payout marked done with
 * no proof is unauditable.
 */
export function CompleteWithdrawalDialog({
  withdrawal,
  pending,
  onClose,
  onSubmit,
}: {
  withdrawal: Withdrawal | null;
  pending: boolean;
  onClose: () => void;
  onSubmit: (values: CompleteWithdrawalInput) => void;
}) {
  const [proofUrl, setProofUrl] = useState("");
  const [reference, setReference] = useState("");
  const [uploading, setUploading] = useState(false);

  // The parent keys this component by withdrawal id, so each payout opens with
  // a fresh form rather than the previous one's proof.
  useEffect(() => {
    if (!withdrawal) return;
    const onKey = (e: KeyboardEvent) => e.key === "Escape" && onClose();
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [withdrawal, onClose]);

  if (!withdrawal) return null;

  const copy = (text: string) => {
    navigator.clipboard.writeText(text);
    toast.success("Disalin");
  };

  const onFile = async (file: File) => {
    setUploading(true);
    try {
      setProofUrl(await uploadImage(file, "payout-proofs"));
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
              Tandai Selesai
            </h2>
            <p className="mt-0.5 text-sm text-muted-foreground">
              Transfer manual dulu lewat m-banking, baru catat buktinya di sini.
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
              <span className="font-bold tabular-nums">{rupiah(withdrawal.amount)}</span>
            </div>
            <div className="flex items-center justify-between gap-2">
              <span className="text-muted-foreground">{withdrawal.bank_name}</span>
              <button
                onClick={() => copy(withdrawal.account_number)}
                className="inline-flex items-center gap-1.5 font-mono font-semibold transition-colors hover:text-[var(--brand-600)]"
              >
                {withdrawal.account_number}
                <Copy className="h-3.5 w-3.5" />
              </button>
            </div>
            <div className="flex items-center justify-between gap-2">
              <span className="text-muted-foreground">Atas nama</span>
              <span className="font-semibold">{withdrawal.account_holder}</span>
            </div>
          </div>

          <div className="grid gap-1.5">
            <Label htmlFor="proof">Bukti transfer</Label>
            <Input
              id="proof"
              type="file"
              accept="image/*"
              disabled={uploading}
              onChange={(e) => {
                const file = e.target.files?.[0];
                if (file) void onFile(file);
              }}
            />
            {uploading && <p className="text-xs text-muted-foreground">Mengunggah…</p>}
            {proofUrl && (
              <a
                href={proofUrl}
                target="_blank"
                rel="noreferrer"
                className="text-xs font-medium text-[var(--brand-600)] underline"
              >
                Lihat bukti terunggah
              </a>
            )}
          </div>

          <div className="grid gap-1.5">
            <Label htmlFor="reference">Nomor referensi bank (opsional)</Label>
            <Input
              id="reference"
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
            onClick={() => onSubmit({ proof_url: proofUrl, transfer_reference: reference || null })}
          >
            {pending ? "Menyimpan…" : "Tandai selesai"}
          </Button>
        </div>
      </div>
    </div>
  );
}
