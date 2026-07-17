"use client";

import { useState } from "react";
import { Building2, Check, Clock, Copy, TriangleAlert, Upload } from "lucide-react";

import { signUpload } from "@/lib/api/events";
import { rupiah } from "@/lib/labels";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import type { PublicBankAccount } from "@/types/api";

/**
 * What a buyer sees when the payment gateway is off: the organizer's account,
 * and a box to upload the transfer receipt for them to verify.
 *
 * Shared by the e-ticket page and the team registration page — the two things
 * a buyer can pay for.
 */
export function ManualTransferPanel({
  bankAccount,
  amount,
  deadlineAt,
  awaitingVerification,
  rejectedReason,
  onSubmit,
  pending = false,
}: {
  bankAccount: PublicBankAccount | null | undefined;
  amount: number;
  deadlineAt: string | null;
  awaitingVerification: boolean;
  rejectedReason: string | null;
  onSubmit: (proofUrl: string) => void;
  pending?: boolean;
}) {
  const [uploading, setUploading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [copied, setCopied] = useState(false);

  if (awaitingVerification) {
    return (
      <Card className="mb-6 flex items-start gap-3 border-[color-mix(in_srgb,var(--brand-600)_40%,transparent)] bg-[color-mix(in_srgb,var(--brand-600)_6%,transparent)] p-5">
        <Clock className="mt-0.5 h-5 w-5 shrink-0 text-[var(--brand-600)]" />
        <div>
          <p className="font-semibold">Bukti terkirim, menunggu verifikasi</p>
          <p className="mt-1 text-sm text-muted-foreground">
            Penyelenggara akan memeriksa buktimu. Halaman ini diperbarui otomatis begitu
            pembayaranmu diterima.
          </p>
        </div>
      </Card>
    );
  }

  if (!bankAccount) return null;

  const copy = async () => {
    await navigator.clipboard.writeText(bankAccount.account_number);
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  };

  const handleFile = async (file: File | undefined) => {
    if (!file) return;
    setUploading(true);
    setError(null);
    try {
      const signed = await signUpload(file.name, file.type, "payment-proofs");
      // No upload_url in dev (object storage isn't configured) — the signed
      // file_url is still usable, so don't block on the PUT.
      if (signed.upload_url) {
        await fetch(signed.upload_url, {
          method: "PUT",
          body: file,
          headers: { "Content-Type": file.type },
        });
      }
      onSubmit(signed.file_url);
    } catch {
      setError("Gagal mengunggah bukti. Coba lagi.");
    } finally {
      setUploading(false);
    }
  };

  return (
    <Card className="mb-6 border-[color-mix(in_srgb,var(--warning)_45%,transparent)] bg-[color-mix(in_srgb,var(--warning)_6%,transparent)] p-5">
      <div className="flex items-start gap-3">
        <Building2 className="mt-0.5 h-5 w-5 shrink-0 text-[var(--warning)]" />
        <div>
          <p className="font-semibold">Transfer manual</p>
          <p className="mt-1 text-sm text-muted-foreground">
            Transfer tepat sejumlah di bawah ke rekening penyelenggara, lalu unggah bukti
            transfernya. Penyelenggara akan memverifikasi secara manual.
          </p>
        </div>
      </div>

      {rejectedReason && (
        <div className="mt-4 flex items-start gap-2 rounded-lg bg-[color-mix(in_srgb,var(--danger)_10%,transparent)] p-3 text-sm">
          <TriangleAlert className="mt-0.5 h-4 w-4 shrink-0 text-[var(--danger)]" />
          <div>
            <p className="font-medium text-[var(--danger)]">Bukti sebelumnya ditolak</p>
            <p className="mt-0.5 text-muted-foreground">{rejectedReason}</p>
          </div>
        </div>
      )}

      <div className="mt-4 grid gap-2 rounded-lg border border-border bg-[var(--surface)] p-4 text-sm">
        <div className="flex items-center justify-between">
          <span className="text-muted-foreground">Bank</span>
          <span className="font-medium">{bankAccount.bank_name}</span>
        </div>
        <div className="flex items-center justify-between gap-3">
          <span className="text-muted-foreground">Nomor rekening</span>
          <span className="flex items-center gap-2">
            <code className="font-mono font-semibold">{bankAccount.account_number}</code>
            <button
              type="button"
              onClick={copy}
              aria-label="Salin nomor rekening"
              className="text-muted-foreground transition-colors hover:text-foreground"
            >
              {copied ? (
                <Check className="h-4 w-4 text-[var(--success)]" />
              ) : (
                <Copy className="h-4 w-4" />
              )}
            </button>
          </span>
        </div>
        <div className="flex items-center justify-between">
          <span className="text-muted-foreground">Atas nama</span>
          <span className="font-medium">{bankAccount.account_holder}</span>
        </div>
        <div className="flex items-center justify-between border-t border-border pt-2">
          <span className="text-muted-foreground">Jumlah transfer</span>
          <span className="font-bold">{rupiah(amount)}</span>
        </div>
        {deadlineAt && (
          <p className="text-xs text-muted-foreground">
            Selesaikan sebelum <strong className="text-foreground">{fmtDeadline(deadlineAt)}</strong>{" "}
            — setelah itu pesanan dibatalkan otomatis.
          </p>
        )}
      </div>

      <label className="mt-4 block">
        <input
          type="file"
          accept="image/*,application/pdf"
          className="hidden"
          disabled={uploading || pending}
          onChange={(e) => handleFile(e.target.files?.[0])}
        />
        <Button asChild disabled={uploading || pending} className="w-full">
          <span className="cursor-pointer">
            <Upload className="h-4 w-4" />
            {uploading || pending ? "Mengunggah…" : "Unggah bukti transfer"}
          </span>
        </Button>
      </label>
      {error && <p className="mt-2 text-xs font-medium text-[var(--danger)]">{error}</p>}
    </Card>
  );
}

function fmtDeadline(iso: string) {
  return new Date(iso).toLocaleString("id-ID", {
    day: "numeric",
    month: "long",
    hour: "2-digit",
    minute: "2-digit",
  });
}
