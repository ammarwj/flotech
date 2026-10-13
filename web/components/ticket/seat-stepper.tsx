"use client";

import { useEffect, useState } from "react";
import { Minus, Plus } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";

/**
 * How many people one ticket order covers: steppers for small changes, a typed
 * field for everything else.
 *
 * Shared by the public shop and the organizer's box office. Both asked the same
 * question with the same two buttons, and both stopped at a number the quota had
 * nothing to do with — a group of 300 went through fifteen checkouts. The field
 * is why this is a component rather than two more copies of the stepper: a text
 * input needs a draft of its own (an empty box is a legal thing to type on the
 * way to "40") and that draft is the part that would have drifted.
 *
 * `max` is `seatCeiling()`'s answer — the tightest day picked, clamped to the
 * API's own per-order guard — so this component never derives a ceiling itself.
 */
export function SeatStepper({
  value,
  max,
  onChange,
  id,
}: {
  value: number;
  max: number;
  onChange: (seats: number) => void;
  id?: string;
}) {
  // What the box shows while it is being typed in. Kept apart from `value`
  // because the states a half-typed number passes through ("", "4" on the way
  // to "40") are not quantities the order can be placed with.
  const [draft, setDraft] = useState<string | null>(null);

  const clamp = (seats: number) => Math.max(1, Math.min(max, seats));

  // The ceiling moves under the buyer: picking a date whose quota is short
  // lowers it after the seats were typed. Without this the form keeps the old
  // number, still looks right, and fails at submit with a 422 naming a date the
  // buyer already chose — the thing seatCeiling() exists to prevent.
  useEffect(() => {
    if (value > max) onChange(max);
  }, [value, max, onChange]);

  const commit = (raw: string) => {
    setDraft(raw);

    const parsed = Number.parseInt(raw, 10);
    if (Number.isFinite(parsed)) onChange(clamp(parsed));
  };

  return (
    <div className="flex items-center gap-2">
      <Button
        type="button"
        size="icon"
        variant="outline"
        onClick={() => {
          setDraft(null);
          onChange(clamp(value - 1));
        }}
        disabled={value <= 1}
        aria-label="Kurangi jumlah"
      >
        <Minus className="h-4 w-4" />
      </Button>
      <Input
        id={id}
        inputMode="numeric"
        className="w-16 text-center font-semibold"
        value={draft ?? String(value)}
        onChange={(e) => commit(e.target.value.replace(/[^0-9]/g, ""))}
        // Dropping the draft on blur is what puts the clamped number back on
        // screen: typing 900 against a quota of 40 commits 40, and the box would
        // otherwise go on showing 900 as though it had been accepted.
        onBlur={() => setDraft(null)}
        aria-label="Jumlah orang"
      />
      <Button
        type="button"
        size="icon"
        variant="outline"
        onClick={() => {
          setDraft(null);
          onChange(clamp(value + 1));
        }}
        disabled={value >= max}
        aria-label="Tambah jumlah"
      >
        <Plus className="h-4 w-4" />
      </Button>
    </div>
  );
}
