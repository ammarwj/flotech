"use client";

import { useEffect, useRef } from "react";

import { tabDateLabel, type DateGroup } from "@/lib/match-dates";
import { cn } from "@/lib/utils";

/**
 * Matchday picker: one pill per day on a single row that scrolls sideways —
 * a long tournament keeps its dates on one line instead of wrapping into a
 * block. The selected day is scrolled into view, so opening on today (or on the
 * last matchday of a finished event) doesn't leave it off-screen.
 */
export function MatchDayTabs({
  groups,
  activeKey,
  onSelect,
}: {
  groups: DateGroup[];
  activeKey?: string;
  onSelect: (key: string) => void;
}) {
  const activeRef = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    activeRef.current?.scrollIntoView({ block: "nearest", inline: "center" });
  }, [activeKey]);

  return (
    // Capped width: a long tournament scrolls its dates instead of stretching
    // the row across the whole page. No negative margin here — combined with a
    // full-width box it would overflow the parent and scroll the whole page.
    <div className="mb-5 flex max-w-6xl gap-1.5 overflow-x-auto pb-1">
      {groups.map((g) => (
        <button
          key={g.key}
          ref={activeKey === g.key ? activeRef : undefined}
          onClick={() => onSelect(g.key)}
          className={cn(
            "shrink-0 whitespace-nowrap rounded-full border px-3.5 py-1.5 text-xs font-semibold transition-colors",
            activeKey === g.key
              ? "border-transparent bg-[var(--brand-600)] text-white"
              : "border-border bg-[var(--surface)] text-muted-foreground hover:text-foreground"
          )}
        >
          {tabDateLabel(g.iso)}
        </button>
      ))}
    </div>
  );
}
