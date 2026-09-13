"use client";

import { useState } from "react";
import { Landmark } from "lucide-react";

import type { BankAccountInput } from "@/lib/api/wallet";
import type { FieldErrors } from "@/lib/api/errors";
import type { BankAccount } from "@/types/api";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

function FieldError({ message }: { message?: string }) {
  if (!message) return null;
  return <p className="text-xs font-medium text-[var(--danger)]">{message}</p>;
}

/**
 * The payout destination. Saving a new account makes it primary; past payouts
 * keep a snapshot of where they actually went, so history stays honest.
 */
export function BankAccountForm({
  current,
  pending,
  fieldErrors,
  onSubmit,
}: {
  current: BankAccount | null;
  pending: boolean;
  fieldErrors: FieldErrors;
  /**
   * Resolves once the account is stored. The form closes on that rather than on
   * submit: a rejected number has to stay on screen with its error, still
   * filled in.
   */
  onSubmit: (values: BankAccountInput) => Promise<unknown>;
}) {
  const [editing, setEditing] = useState(!current);
  const [bankName, setBankName] = useState(current?.bank_name ?? "");
  const [accountNumber, setAccountNumber] = useState("");
  const [accountHolder, setAccountHolder] = useState(current?.account_holder ?? "");

  if (current && !editing) {
    return (
      <Card className="flex flex-wrap items-center justify-between gap-4 p-5">
        <div className="flex items-center gap-3">
          <div className="grid h-10 w-10 place-items-center rounded-lg bg-[var(--tint)] text-[var(--brand-600)]">
            <Landmark className="h-5 w-5" />
          </div>
          <div>
            <p className="font-semibold">{current.bank_name}</p>
            <p className="text-sm text-muted-foreground">
              {current.account_number} &middot; {current.account_holder}
            </p>
          </div>
        </div>
        <Button
          variant="outline"
          onClick={() => {
            // Prefill from the stored account, except the number: the API
            // masks it for the organizer (only the super admin who makes the
            // transfer sees it in full), so the masked form would either be
            // saved back as the real number or have to be cleared on submit.
            setBankName(current.bank_name);
            setAccountHolder(current.account_holder);
            setAccountNumber("");
            setEditing(true);
          }}
        >
          Ganti rekening
        </Button>
      </Card>
    );
  }

  return (
    <Card className="p-5">
      <form
        className="grid gap-4"
        onSubmit={async (e) => {
          e.preventDefault();
          try {
            await onSubmit({
              bank_name: bankName,
              account_number: accountNumber,
              account_holder: accountHolder,
            });
            setEditing(false);
          } catch {
            // The caller already surfaced it — either as a field error below or
            // a toast. Staying open is the whole point.
          }
        }}
      >
        <div className="grid gap-1.5">
          <Label htmlFor="bank_name">Nama bank</Label>
          <Input
            id="bank_name"
            value={bankName}
            onChange={(e) => setBankName(e.target.value)}
            placeholder="BCA"
          />
          <FieldError message={fieldErrors.bank_name} />
        </div>

        <div className="grid gap-1.5">
          <Label htmlFor="account_number">Nomor rekening</Label>
          <Input
            id="account_number"
            inputMode="numeric"
            value={accountNumber}
            onChange={(e) => setAccountNumber(e.target.value)}
            placeholder={current ? current.account_number : "1234567890"}
          />
          <FieldError message={fieldErrors.account_number} />
          {current && (
            <p className="text-xs text-muted-foreground">
              Nomor rekening tersimpan hanya ditampilkan sebagian demi keamanan, jadi ketik
              ulang lengkap — termasuk kalau nomornya tidak berubah.
            </p>
          )}
        </div>

        <div className="grid gap-1.5">
          <Label htmlFor="account_holder">Nama pemilik rekening</Label>
          <Input
            id="account_holder"
            value={accountHolder}
            onChange={(e) => setAccountHolder(e.target.value)}
            placeholder="Sesuai buku tabungan"
          />
          <FieldError message={fieldErrors.account_holder} />
          <p className="text-xs text-muted-foreground">
            Pastikan nama sesuai rekening — transfer yang ditolak bank akan memperlambat pencairan.
          </p>
        </div>

        <div className="flex gap-2">
          <Button type="submit" disabled={pending}>
            {pending ? "Menyimpan…" : "Simpan rekening"}
          </Button>
          {current && (
            <Button type="button" variant="ghost" onClick={() => setEditing(false)}>
              Batal
            </Button>
          )}
        </div>
      </form>
    </Card>
  );
}
