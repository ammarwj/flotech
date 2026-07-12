"use client";

import { useEffect, useState } from "react";
import { Banknote, X } from "lucide-react";

import type { FieldErrors } from "@/lib/api/errors";
import type { BankAccount, Wallet } from "@/types/api";
import { rupiah } from "@/lib/labels";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";

/**
 * Requesting a payout debits the wallet immediately, so the summary shows the
 * organizer exactly what leaves the balance — the admin fee included.
 */
export function WithdrawDialog({
  wallet,
  bank,
  open,
  pending,
  fieldErrors,
  onClose,
  onSubmit,
}: {
  wallet: Wallet;
  bank: BankAccount;
  open: boolean;
  pending: boolean;
  fieldErrors: FieldErrors;
  onClose: () => void;
  onSubmit: (values: { amount: number; note?: string }) => void;
}) {
  const [amount, setAmount] = useState("");
  const [note, setNote] = useState("");

  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => e.key === "Escape" && onClose();
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [open, onClose]);

  if (!open) return null;

  const { minimum_withdrawal: minimum, admin_fee: fee } = wallet.rules;
  const value = Number(amount) || 0;
  const totalDebit = value > 0 ? value + fee : 0;
  const remaining = wallet.balance_available - totalDebit;

  const belowMinimum = value > 0 && value < minimum;
  const overBalance = totalDebit > wallet.balance_available;
  const canSubmit = value > 0 && !belowMinimum && !overBalance && !pending;

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
            <Banknote className="h-5 w-5" />
          </span>
          <div className="min-w-0 flex-1">
            <h2 className="text-base font-bold" style={{ fontFamily: "var(--font-display)" }}>
              Tarik Dana
            </h2>
            <p className="mt-0.5 text-sm text-muted-foreground">
              Dana ditransfer manual oleh admin ke rekening kamu, biasanya 1–2 hari kerja.
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
          <div className="rounded-lg border border-border bg-[var(--bg-alt)] p-3 text-sm">
            <p className="font-semibold">{bank.bank_name}</p>
            <p className="text-muted-foreground">
              {bank.account_number} &middot; {bank.account_holder}
            </p>
          </div>

          <div className="grid gap-1.5">
            <Label htmlFor="amount">Jumlah penarikan</Label>
            <Input
              id="amount"
              inputMode="numeric"
              value={amount}
              onChange={(e) => setAmount(e.target.value.replace(/[^0-9]/g, ""))}
              placeholder={String(minimum)}
            />
            <p className="text-xs text-muted-foreground">
              Saldo tersedia {rupiah(wallet.balance_available)} &middot; minimal{" "}
              {rupiah(minimum)}.
            </p>
            {belowMinimum && (
              <p className="text-xs font-medium text-[var(--danger)]">
                Minimal penarikan {rupiah(minimum)}.
              </p>
            )}
            {overBalance && (
              <p className="text-xs font-medium text-[var(--danger)]">
                Saldo tidak cukup untuk jumlah ini beserta biaya admin.
              </p>
            )}
            {fieldErrors.amount && (
              <p className="text-xs font-medium text-[var(--danger)]">{fieldErrors.amount}</p>
            )}
          </div>

          {value > 0 && (
            <dl className="grid gap-1.5 rounded-lg border border-border p-3 text-sm">
              <div className="flex justify-between">
                <dt className="text-muted-foreground">Kamu terima</dt>
                <dd className="font-semibold">{rupiah(value)}</dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-muted-foreground">Biaya admin</dt>
                <dd>{rupiah(fee)}</dd>
              </div>
              <div className="flex justify-between border-t border-border pt-1.5">
                <dt className="text-muted-foreground">Total dipotong</dt>
                <dd className="font-semibold">{rupiah(totalDebit)}</dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-muted-foreground">Sisa saldo</dt>
                <dd>{rupiah(remaining)}</dd>
              </div>
            </dl>
          )}

          <div className="grid gap-1.5">
            <Label htmlFor="note">Catatan (opsional)</Label>
            <Textarea
              id="note"
              rows={2}
              value={note}
              onChange={(e) => setNote(e.target.value)}
              placeholder="Mis. untuk pembayaran vendor"
            />
          </div>
        </div>

        <div className="flex justify-end gap-2 border-t border-border p-5">
          <Button variant="ghost" onClick={onClose}>
            Batal
          </Button>
          <Button
            disabled={!canSubmit}
            onClick={() => onSubmit({ amount: value, note: note || undefined })}
          >
            {pending ? "Mengirim…" : "Ajukan penarikan"}
          </Button>
        </div>
      </div>
    </div>
  );
}
