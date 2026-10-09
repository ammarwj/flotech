"use client";

import { Check } from "lucide-react";

import { dayChipLabel } from "@/lib/match-dates";
import { cn } from "@/lib/utils";

export type DayChip = {
  date: string;
  /** Seats left on this date, null when unlimited. Rendered when given. */
  remaining?: number | null;
  disabled?: boolean;
  /** Why it is disabled, as a tooltip. */
  reason?: string;
};

/**
 * Multi-select day chips, used by both sides of per-day ticketing: the
 * organizer picking which dates a category sells, and the buyer picking which
 * days they attend.
 *
 * One component for both because the refusals are the same shape on both sides
 * — a date already sold cannot be dropped, a date that is full cannot be
 * picked — and a chip that looks identical but behaves differently on the two
 * screens would be the bug this prevents.
 *
 * A sibling of MatchDayTabs rather than a prop on it: that one is a single-
 * active tab strip with scroll-into-view behaviour nobody wants here, and the
 * selected set is the whole difference. Wraps instead of scrolling sideways,
 * because a form field that hides half its options off-screen hides exactly
 * the date somebody meant to tick.
 */
export function DayPickerChips({
  days,
  selected,
  onToggle,
  disabled,
}: {
  days: DayChip[];
  selected: string[];
  onToggle: (date: string) => void;
  /** Locks every chip — the whole control is read-only. */
  disabled?: boolean;
}) {
  if (days.length === 0) return null;

  return (
    <div className="flex flex-wrap gap-1.5">
      {days.map((day) => {
        const active = selected.includes(day.date);
        // An already-picked chip is never locked by its own quota: the seats it
        // is holding are this buyer's, and locking it would trap them with a
        // selection they cannot undo.
        const locked = Boolean(disabled) || (Boolean(day.disabled) && !active);

        return (
          <button
            key={day.date}
            type="button"
            onClick={() => !locked && onToggle(day.date)}
            disabled={locked}
            title={locked ? day.reason : undefined}
            aria-pressed={active}
            className={cn(
              "flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-full border px-3.5 py-1.5 text-xs font-semibold transition-colors",
              active
                ? "border-transparent bg-[var(--brand-600)] text-white"
                : "border-border bg-[var(--surface)] text-muted-foreground hover:text-foreground",
              locked && "cursor-not-allowed opacity-50 hover:text-muted-foreground",
            )}
          >
            {active && <Check className="h-3 w-3" />}
            {dayChipLabel(day.date)}
            {day.remaining !== undefined && day.remaining !== null && (
              <span className={cn("font-normal", active ? "text-white/75" : "text-muted-foreground")}>
                · sisa {day.remaining}
              </span>
            )}
          </button>
        );
      })}
    </div>
  );
}
