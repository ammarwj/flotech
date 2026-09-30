"use client";

import { useState } from "react";
import { Landmark, Smartphone } from "lucide-react";

import type { BankAccountInput } from "@/lib/api/wallet";
import type { FieldErrors } from "@/lib/api/errors";
import type { PayoutAccountType, BankAccount } from "@/types/api";
import { EWALLET_PROVIDERS, payoutLabels, providerKeyFor } from "@/lib/payout";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";

function FieldError({ message }: { message?: string }) {
  if (!message) return null;
  return <p className="text-xs font-medium text-[var(--danger)]">{message}</p>;
}

/**
 * The payout destination — a bank account or an e-wallet. Saving a new one makes
 * it primary; past payouts keep a snapshot of where they actually went, so
 * history stays honest.
 *
 * One form for both kinds because they are one decision with the same three
 * facts (institution, number, name); only the words and the number's validation
 * differ, and both of those come from `lib/payout.ts` rather than being spelled
 * out per branch.
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
  const [type, setType] = useState<PayoutAccountType>(
    current?.account_type ?? "bank",
  );
  const [bankName, setBankName] = useState(
    current?.account_type === "ewallet"
      ? providerKeyFor(current.bank_name)
      : (current?.bank_name ?? ""),
  );
  const [accountNumber, setAccountNumber] = useState("");
  const [accountHolder, setAccountHolder] = useState(
    current?.account_holder ?? "",
  );

  const isEwallet = type === "ewallet";
  const words = payoutLabels(type);

  if (current && !editing) {
    const savedWords = payoutLabels(current.account_type);
    const Icon = current.account_type === "ewallet" ? Smartphone : Landmark;

    return (
      <Card className="flex flex-wrap items-center justify-between gap-4 p-5">
        <div className="flex items-center gap-3">
          <div className="grid h-10 w-10 place-items-center rounded-lg bg-[var(--tint)] text-[var(--brand-600)]">
            <Icon className="h-5 w-5" />
          </div>
          <div>
            <p className="font-semibold">{current.bank_name}</p>
            <p className="text-sm text-muted-foreground">
              {current.account_number} &middot; {current.account_holder}
            </p>
            <p className="mt-0.5 text-xs text-muted-foreground">
              {savedWords.kind}
            </p>
          </div>
        </div>
        <Button
          variant="outline"
          onClick={() => {
            // Prefill from the stored destination, except the number: the API
            // masks it for the organizer (only the super admin who makes the
            // transfer sees it in full), so the masked form would either be
            // saved back as the real number or have to be cleared on submit.
            setType(current.account_type);
            // An e-wallet stores its provider's *label*; the select works in
            // keys, so map back rather than leaving the dropdown unset.
            setBankName(
              current.account_type === "ewallet"
                ? providerKeyFor(current.bank_name)
                : current.bank_name,
            );
            setAccountHolder(current.account_holder);
            setAccountNumber("");
            setEditing(true);
          }}
        >
          Ganti tujuan
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
              account_type: type,
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
          <Label htmlFor="account_type">Jenis tujuan</Label>
          <Select
            id="account_type"
            value={type}
            onChange={(e) => {
              const next = e.target.value as PayoutAccountType;
              setType(next);
              // The institution field means different things per kind — a bank
              // name left in the provider select would submit as an invalid
              // key, and a provider label left in the bank field is not a bank.
              setBankName("");
            }}
          >
            <option value="bank">Rekening bank</option>
            <option value="ewallet">E-wallet (GoPay, DANA, dll.)</option>
          </Select>
          <FieldError message={fieldErrors.account_type} />
        </div>

        <div className="grid gap-1.5">
          <Label htmlFor="bank_name">{words.institution}</Label>
          {isEwallet ? (
            <Select
              id="bank_name"
              value={bankName}
              onChange={(e) => setBankName(e.target.value)}
            >
              <option value="">Pilih penyedia…</option>
              {Object.entries(EWALLET_PROVIDERS).map(([key, label]) => (
                <option key={key} value={key}>
                  {label}
                </option>
              ))}
            </Select>
          ) : (
            <Input
              id="bank_name"
              value={bankName}
              onChange={(e) => setBankName(e.target.value)}
              placeholder="BCA"
            />
          )}
          <FieldError message={fieldErrors.bank_name} />
        </div>

        <div className="grid gap-1.5">
          <Label htmlFor="account_number">{words.number}</Label>
          <Input
            id="account_number"
            inputMode={isEwallet ? "tel" : "numeric"}
            value={accountNumber}
            onChange={(e) => setAccountNumber(e.target.value)}
            placeholder={isEwallet ? "081234567890" : "1234567890"}
          />
          <FieldError message={fieldErrors.account_number} />

          {current && (
            <p className="text-xs text-muted-foreground">
              {words.number} tersimpan disamarkan. Ketik ulang lengkap.
            </p>
          )}
        </div>

        <div className="grid gap-1.5">
          <Label htmlFor="account_holder">{words.holder}</Label>
          <Input
            id="account_holder"
            value={accountHolder}
            onChange={(e) => setAccountHolder(e.target.value)}
            placeholder={
              isEwallet ? "Sesuai akun e-wallet" : "Sesuai buku tabungan"
            }
          />
          <FieldError message={fieldErrors.account_holder} />
          <p className="text-xs text-muted-foreground">
            Harus sama dengan nama di {isEwallet ? "akun e-wallet" : "rekening"}.
          </p>
        </div>

        <div className="flex gap-2">
          <Button type="submit" disabled={pending}>
            {pending ? "Menyimpan…" : "Simpan tujuan"}
          </Button>
          {current && (
            <Button
              type="button"
              variant="ghost"
              onClick={() => setEditing(false)}
            >
              Batal
            </Button>
          )}
        </div>
      </form>
    </Card>
  );
}
