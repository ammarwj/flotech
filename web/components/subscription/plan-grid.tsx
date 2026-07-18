import type { ReactNode } from "react";

/**
 * Lays out plan cards for however many plans there actually are.
 *
 * The catalog is data-driven — a plan can be unpublished, so the row is 2, 3 or
 * 4 wide — but the grids were pinned to `lg:grid-cols-4`. With three plans that
 * left a dead fourth track: the cards sat against it, off the page's rhythm.
 *
 * `auto-fit` collapses the empty tracks, and the count-derived `maxWidth` stops
 * a short row from stretching each card across the whole content column. At the
 * full four the cap exceeds the available width, so that (the common) case is
 * laid out exactly as before.
 *
 * Left-aligned rather than centred: everything else on these pages hangs off the
 * left edge, so a centred short row would read as misaligned instead of tidy.
 */
export function PlanGrid({ count, children }: { count: number; children: ReactNode }) {
  return (
    <div
      className="grid gap-4"
      style={{
        // min(…, 100%) so a card never outgrows a narrow container.
        gridTemplateColumns: "repeat(auto-fit, minmax(min(200px, 100%), 1fr))",
        // 280px is chosen so four cards exceed the content column and the cap
        // stops binding — the common case then lays out exactly as the old
        // lg:grid-cols-4 did (267px cards), while a short row keeps a similar
        // card size instead of stretching.
        maxWidth: `calc(${count} * 280px + ${Math.max(count - 1, 0)} * 1rem)`,
      }}
    >
      {children}
    </div>
  );
}
