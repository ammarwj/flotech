"use client";

import { useEffect, useRef } from "react";
import type { LucideIcon } from "lucide-react";

import { cn } from "@/lib/utils";

export type PillTabItem = {
  key: string;
  label: string;
  icon?: LucideIcon;
};

/** Filled brand pill (page tabs) or soft tint (category filter). */
export type PillTone = "solid" | "tint";

const TONE: Record<PillTone, { strip: string; active: string; padding: string }> = {
  solid: {
    strip: "font-semibold",
    active: "bg-[var(--brand-600)] text-white",
    padding: "px-4",
  },
  tint: {
    strip: "font-medium",
    active: "bg-[var(--tint)] text-[var(--brand-700)]",
    padding: "px-3.5",
  },
};

/**
 * The public event page's pill strips — the tab bar and the category filter,
 * which are the same control with different colours.
 *
 * Wraps from `sm` up, scrolls sideways below it. An event with a dozen
 * long category names ("TUNGGAL USIA DINI PUTRA"…) has nowhere to put them on a
 * desktop row, and hiding two thirds of them behind a sideways scroll on the
 * widest screen is the wrong trade; on a phone the same wrap would eat several
 * rows of a screen that has none to spare.
 *
 * Two details are load-bearing:
 *
 * - `overflow-x-auto` holds at every width. It used to flip to
 *   `sm:overflow-visible`, which let the pills spill straight out of the
 *   rounded border once they no longer fit — the bug this component replaces.
 *   With wrapping there is nothing to overflow anyway, so it costs nothing.
 * - `rounded-3xl`, not `rounded-full`. A browser scales a radius down to half
 *   the box, so a single row still renders as a perfect pill, while a wrapped
 *   block gets sane corners instead of giant lozenge ends.
 */
export function PillTabs({
  items,
  activeKey,
  onSelect,
  tone = "solid",
}: {
  items: PillTabItem[];
  activeKey: string;
  onSelect: (key: string) => void;
  tone?: PillTone;
}) {
  const scrollerRef = useRef<HTMLDivElement>(null);
  const activeRef = useRef<HTMLButtonElement>(null);
  const t = TONE[tone];

  // Centre the selected pill while the strip is still a scrolling row. Without
  // it, landing on a category that sits eighth in the list shows a strip that
  // looks like it starts at "Semua" with nothing selected.
  useEffect(() => {
    const box = scrollerRef.current;
    const pill = activeRef.current;
    if (!box || !pill) return;

    // Assigning scrollLeft rather than scrollIntoView(): the latter also walks
    // up and scrolls the *page*, which on mount would yank the visitor past the
    // event header. Once the strip wraps there is nothing to scroll and this is
    // a no-op.
    box.scrollLeft = Math.max(0, pill.offsetLeft - (box.clientWidth - pill.offsetWidth) / 2);
  }, [activeKey]);

  return (
    <div
      ref={scrollerRef}
      className={cn(
        // relative: makes this the offsetParent the centring maths measures against.
        "pill-strip relative inline-flex max-w-full items-center gap-1 overflow-x-auto rounded-3xl border border-border bg-[var(--surface)] p-1 text-sm sm:flex-wrap",
        t.strip
      )}
    >
      {items.map(({ key, label, icon: Icon }) => {
        const active = key === activeKey;
        return (
          <button
            key={key}
            ref={active ? activeRef : undefined}
            onClick={() => onSelect(key)}
            aria-current={active ? "page" : undefined}
            className={cn(
              "inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-full py-1.5 transition-colors",
              t.padding,
              active ? t.active : "text-muted-foreground hover:text-foreground"
            )}
          >
            {Icon && <Icon className="h-4 w-4" />}
            {label}
          </button>
        );
      })}
    </div>
  );
}
