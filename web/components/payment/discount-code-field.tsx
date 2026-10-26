"use client";

import { useState } from "react";
import { useMutation } from "@tanstack/react-query";

import { previewParticipantDiscount } from "@/lib/api/discount";
import { parseApiError } from "@/lib/api/errors";
import { rupiah } from "@/lib/labels";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import type { DiscountPreview } from "@/types/api";

/**
 * The discount code input and the price summary beneath it, for both
 * participant registration forms.
 *
 * One component rather than the block copied into each form: the two differ
 * only in which category id they preview against, and three copies of "clear
 * the picked channel when the amount moves" is three places for that rule to be
 * forgotten in — which shows up as a fee quoted over a price nobody is charged.
 *
 * **The parent owns the applied preview; only the raw text lives here.** What
 * the parent does with that preview is the whole rule: `applied.amount` is what
 * the channel picker is given and `applied.code` is what the submit sends, so
 * keeping it in here would leave the parent free to disagree with it.
 */
export function DiscountCodeField({
  orgSlug,
  eventSlug,
  category,
  price,
  applied,
  onApply,
  disabled,
  error,
  onError,
}: {
  orgSlug: string;
  eventSlug: string;
  /** Which rail — a competition category, or a race category. */
  category: { categoryId: string } | { raceCategoryId: string };
  /** The catalogue price, for the summary's top line. */
  price: number;
  applied: DiscountPreview | null;
  /**
   * The server's answer, or null when the field was edited. The parent must
   * clear its picked channel on every call: a discount moves the amount a
   * percentage fee is taken from, so the previous quote is stale.
   */
  onApply: (preview: DiscountPreview | null) => void;
  disabled?: boolean;
  error: string | null;
  onError: (message: string | null) => void;
}) {
  const [code, setCode] = useState("");
  const payable = applied ? applied.amount : price;

  const preview = useMutation({
    mutationFn: (value: string) =>
      previewParticipantDiscount(orgSlug, eventSlug, value, category),
    onSuccess: (data) => {
      onApply(data);
      onError(null);
    },
    onError: (err) => {
      const parsed = parseApiError(err, "Kode diskon tidak bisa dipakai.");
      onApply(null);
      // Refusals come back 422 on the field, which is where they belong — a
      // toast would leave the input looking accepted.
      onError(parsed.fieldErrors.discount_code ?? parsed.message);
    },
  });

  const apply = () => {
    const trimmed = code.trim();
    if (trimmed) preview.mutate(trimmed);
  };

  return (
    <div className="grid gap-3">
      <div className="grid gap-2">
        <Label htmlFor="discount-code">Kode diskon (opsional)</Label>
        <div className="flex gap-2">
          <Input
            id="discount-code"
            value={code}
            placeholder="EARLYBIRD"
            autoCapitalize="characters"
            disabled={disabled}
            onChange={(e) => {
              setCode(e.target.value);
              // Editing invalidates the server's answer: what the summary and
              // the channel picker show must never outlive the code they came
              // from.
              onApply(null);
              onError(null);
            }}
            onKeyDown={(e) => {
              if (e.key !== "Enter") return;
              // The field sits inside a form on both rails; Enter here must
              // check the code, not submit the registration.
              e.preventDefault();
              apply();
            }}
          />
          <Button
            type="button"
            variant="secondary"
            disabled={disabled || !code.trim() || preview.isPending}
            onClick={apply}
          >
            {preview.isPending ? "Memeriksa…" : "Pakai"}
          </Button>
        </div>
        {error && <p className="text-sm text-[var(--danger)]">{error}</p>}
      </div>

      <div className="grid gap-1 rounded-lg border p-3 text-sm">
        <div className="flex justify-between">
          <span className="text-muted-foreground">Biaya pendaftaran</span>
          <span className={applied ? "text-muted-foreground line-through" : undefined}>
            {rupiah(price)}
          </span>
        </div>
        {applied && (
          <div className="flex justify-between text-[var(--success)]">
            <span>Diskon {applied.code}</span>
            <span>&minus;{rupiah(applied.discount_amount)}</span>
          </div>
        )}
        <div className="flex justify-between font-medium">
          <span>Yang dibayar</span>
          <span>{rupiah(payable)}</span>
        </div>
        {payable === 0 && (
          <p className="text-xs text-muted-foreground">
            Tidak ada yang perlu dibayar — pendaftarannya langsung aktif.
          </p>
        )}
      </div>
    </div>
  );
}
