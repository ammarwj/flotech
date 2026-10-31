"use client";

import * as React from "react";

import { cn } from "@/lib/utils";
import { DatePicker } from "@/components/ui/date-picker";
import { Input } from "@/components/ui/input";

/** Wall clock used when a date is picked before a time. */
const DEFAULT_TIME = "00:00";

/**
 * Date + time field that reads/writes `YYYY-MM-DDTHH:mm` — the same string
 * `<input type="datetime-local">` carries, so it is a drop-in replacement for
 * one, and `new Date()` still reads it in the browser's own zone (the note in
 * `race-periods-dialog.tsx` about WIB wall clocks applies unchanged).
 *
 * It composes `DatePicker` rather than growing a second calendar: the calendar
 * is the part people recognise from the event form, and two copies of it would
 * drift in exactly the way nobody notices until one of them opens on the wrong
 * month. The time half stays native — `<input type="time">` is a wheel on
 * mobile and two typeable fields on desktop, and there is no custom affordance
 * worth losing that for.
 */
export function DateTimePicker({
  id,
  value,
  onChange,
  min,
  max,
  datePlaceholder,
  clearable,
  disabled,
  className,
  "aria-invalid": ariaInvalid,
}: {
  id?: string;
  /** Selected instant as `YYYY-MM-DDTHH:mm`, or empty/null when none. */
  value?: string | null;
  /** Emits `YYYY-MM-DDTHH:mm`, or `""` when cleared. */
  onChange: (value: string) => void;
  /** Earliest selectable date as `YYYY-MM-DD`. */
  min?: string;
  /** Latest selectable date as `YYYY-MM-DD`. */
  max?: string;
  datePlaceholder?: string;
  clearable?: boolean;
  disabled?: boolean;
  className?: string;
  "aria-invalid"?: boolean;
}) {
  const [date = "", time = ""] = (value ?? "").split("T");

  /**
   * A date with no time is not a value this field can emit, so picking one
   * fills the clock in. Clearing the date clears the whole thing — a time
   * alone names no instant either.
   */
  const setDate = (next: string) => onChange(next ? `${next}T${time || DEFAULT_TIME}` : "");

  // Guarded the same way: a time typed before a date would emit "T09:00".
  const setTime = (next: string) => {
    if (!date) return;
    onChange(`${date}T${next || DEFAULT_TIME}`);
  };

  return (
    <div className={cn("flex gap-2", className)}>
      <DatePicker
        id={id}
        value={date}
        onChange={setDate}
        min={min}
        max={max}
        placeholder={datePlaceholder}
        clearable={clearable}
        disabled={disabled}
        aria-invalid={ariaInvalid}
        className="flex-1"
      />
      <Input
        type="time"
        aria-label="Waktu"
        value={time}
        disabled={disabled || !date}
        onChange={(e) => setTime(e.target.value)}
        className="w-[7.5rem] shrink-0"
      />
    </div>
  );
}
