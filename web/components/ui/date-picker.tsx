"use client";

import * as React from "react";
import {
  addMonths,
  eachDayOfInterval,
  endOfMonth,
  endOfWeek,
  format,
  isSameDay,
  isSameMonth,
  isToday,
  parseISO,
  startOfMonth,
  startOfWeek,
  subMonths,
} from "date-fns";
import { id as idLocale } from "date-fns/locale/id";
import { Calendar, ChevronLeft, ChevronRight } from "lucide-react";

import { cn } from "@/lib/utils";

const WEEKDAYS = ["Min", "Sen", "Sel", "Rab", "Kam", "Jum", "Sab"];
/** Canonical value format shared with the API (`YYYY-MM-DD`). */
const ISO = "yyyy-MM-dd";

/**
 * Calendar date picker that reads/writes plain `YYYY-MM-DD` strings, so it's a
 * drop-in replacement for `<input type="date">`. Built on date-fns to avoid
 * pulling in a calendar/popover dependency (same philosophy as `select.tsx`).
 */
export function DatePicker({
  id,
  value,
  onChange,
  min,
  placeholder = "Pilih tanggal",
  disabled,
  className,
  "aria-invalid": ariaInvalid,
}: {
  id?: string;
  /** Selected date as `YYYY-MM-DD`, or empty when none. */
  value?: string;
  onChange: (value: string) => void;
  /** Earliest selectable date as `YYYY-MM-DD`; earlier days are disabled. */
  min?: string;
  placeholder?: string;
  disabled?: boolean;
  className?: string;
  "aria-invalid"?: boolean;
}) {
  const [open, setOpen] = React.useState(false);
  const selected = value ? parseISO(value) : null;
  const [viewMonth, setViewMonth] = React.useState<Date>(
    () => startOfMonth(selected ?? (min ? parseISO(min) : new Date()))
  );
  const rootRef = React.useRef<HTMLDivElement>(null);

  const toggle = () => {
    // Jump the calendar to the selected month right before opening.
    if (!open) setViewMonth(startOfMonth(selected ?? (min ? parseISO(min) : new Date())));
    setOpen((o) => !o);
  };

  // Close on outside click / Escape.
  React.useEffect(() => {
    if (!open) return;
    const onDown = (e: MouseEvent) => {
      if (rootRef.current && !rootRef.current.contains(e.target as Node)) setOpen(false);
    };
    const onKey = (e: KeyboardEvent) => e.key === "Escape" && setOpen(false);
    document.addEventListener("mousedown", onDown);
    document.addEventListener("keydown", onKey);
    return () => {
      document.removeEventListener("mousedown", onDown);
      document.removeEventListener("keydown", onKey);
    };
  }, [open]);

  const days = eachDayOfInterval({
    start: startOfWeek(startOfMonth(viewMonth), { weekStartsOn: 0 }),
    end: endOfWeek(endOfMonth(viewMonth), { weekStartsOn: 0 }),
  });

  const isDisabledDay = (d: Date) => !!min && format(d, ISO) < min;

  const pick = (d: Date) => {
    if (isDisabledDay(d)) return;
    onChange(format(d, ISO));
    setOpen(false);
  };

  return (
    <div className="relative" ref={rootRef}>
      {/* aria-invalid is valid on <button> and lets the form focus the first
          invalid field; the jsx-a11y rule flags it as a false positive. */}
      {/* eslint-disable-next-line jsx-a11y/role-supports-aria-props */}
      <button
        type="button"
        id={id}
        disabled={disabled}
        aria-invalid={ariaInvalid}
        aria-haspopup="dialog"
        aria-expanded={open}
        onClick={toggle}
        className={cn(
          "flex h-10 w-full items-center gap-2 rounded-md border border-input bg-background px-3 py-2 text-left text-sm ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 aria-[invalid=true]:border-destructive aria-[invalid=true]:focus-visible:ring-destructive",
          className
        )}
      >
        <Calendar className="h-4 w-4 shrink-0 text-muted-foreground" />
        <span className={cn("truncate", !selected && "text-muted-foreground")}>
          {selected ? format(selected, "d MMMM yyyy", { locale: idLocale }) : placeholder}
        </span>
      </button>

      {open && (
        <div
          role="dialog"
          className="absolute left-0 top-[calc(100%+0.375rem)] z-50 w-[17.5rem] rounded-xl border border-border bg-popover p-3 text-popover-foreground shadow-[var(--shadow-md)]"
        >
          <div className="mb-2 flex items-center justify-between">
            <button
              type="button"
              onClick={() => setViewMonth((m) => subMonths(m, 1))}
              className="grid h-7 w-7 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground"
              aria-label="Bulan sebelumnya"
            >
              <ChevronLeft className="h-4 w-4" />
            </button>
            <span className="text-sm font-semibold" style={{ fontFamily: "var(--font-display)" }}>
              {format(viewMonth, "MMMM yyyy", { locale: idLocale })}
            </span>
            <button
              type="button"
              onClick={() => setViewMonth((m) => addMonths(m, 1))}
              className="grid h-7 w-7 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground"
              aria-label="Bulan berikutnya"
            >
              <ChevronRight className="h-4 w-4" />
            </button>
          </div>

          <div className="mb-1 grid grid-cols-7 text-center text-[0.7rem] font-medium text-muted-foreground">
            {WEEKDAYS.map((w) => (
              <span key={w} className="py-1">
                {w}
              </span>
            ))}
          </div>

          <div className="grid grid-cols-7 gap-0.5">
            {days.map((d) => {
              const outside = !isSameMonth(d, viewMonth);
              const isSelected = selected && isSameDay(d, selected);
              const disabledDay = isDisabledDay(d);
              return (
                <button
                  key={d.toISOString()}
                  type="button"
                  disabled={disabledDay}
                  // The visible label is just the day number, which is ambiguous
                  // on its own (and repeats for the trailing days of the
                  // neighbouring months).
                  aria-label={format(d, "d MMMM yyyy", { locale: idLocale })}
                  aria-current={isToday(d) ? "date" : undefined}
                  onClick={() => pick(d)}
                  className={cn(
                    "grid h-8 w-8 place-items-center justify-self-center rounded-md text-sm transition-colors",
                    "hover:bg-accent hover:text-accent-foreground",
                    outside && "text-muted-foreground/50",
                    isToday(d) && !isSelected && "font-semibold text-[var(--brand-600)]",
                    isSelected &&
                      "bg-[var(--brand-600)] font-semibold text-white hover:bg-[var(--brand-700)] hover:text-white",
                    disabledDay && "cursor-not-allowed text-muted-foreground/30 line-through hover:bg-transparent"
                  )}
                >
                  {format(d, "d")}
                </button>
              );
            })}
          </div>
        </div>
      )}
    </div>
  );
}
