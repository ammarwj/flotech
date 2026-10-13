"use client";

import { useCallback, useEffect, useId, useLayoutEffect, useRef, useState } from "react";
import { Info } from "lucide-react";

import { cn } from "@/lib/utils";

/**
 * A small "what does this field mean?" marker: an icon beside a label that
 * reveals a sentence or two of explanation.
 *
 * Opens on hover *and* on click. Hover alone would make it invisible on a
 * phone, which is where an organizer actually fills a match-day form — and the
 * fields it explains are exactly the ones nobody can guess from the label.
 *
 * Deliberately not a Radix popover: the repo pulls in dialog, label and slot
 * only, and a floating-position engine is a heavy dependency for a panel that
 * never needs to flip or collide-detect. `align` covers the one real case, a
 * field sitting in the last column.
 *
 * What it does need is a horizontal clamp. `max-w` caps the panel's *width*,
 * never where it starts: an icon sitting ~100px in — the fee rows in
 * `ChannelPicker`, any label in the second column of a form — still hangs a
 * 256px panel past the right edge, and on a phone that widens the document and
 * the whole page gains a sideways scroll. So the panel is measured once it
 * opens and shifted back inside a 12px gutter.
 */
const GUTTER = 12;
export function InfoHint({
  label,
  align = "start",
  children,
}: {
  /** Screen-reader name, e.g. "Penjelasan kartu kuning untuk 1 larangan". */
  label: string;
  /** Which edge the panel hangs from. Use "end" in a right-hand column. */
  align?: "start" | "end";
  children: React.ReactNode;
}) {
  const [open, setOpen] = useState(false);
  const [shift, setShift] = useState(0);
  const id = useId();
  const ref = useRef<HTMLSpanElement>(null);
  const panelRef = useRef<HTMLSpanElement>(null);

  // Measured from the unshifted panel, so `shift` is never read back as input —
  // the transform is applied separately and the rect below is the natural one.
  const clamp = useCallback(() => {
    const panel = panelRef.current;
    if (!panel) return;

    const rect = panel.getBoundingClientRect();
    const overflowRight = rect.right - shift - (window.innerWidth - GUTTER);
    const overflowLeft = GUTTER - (rect.left - shift);

    // Right edge first: a panel too wide for the viewport would otherwise be
    // pushed off the left instead, which is the same bug mirrored.
    setShift(overflowRight > 0 ? -overflowRight : overflowLeft > 0 ? overflowLeft : 0);
  }, [shift]);

  useLayoutEffect(() => {
    if (!open) {
      setShift(0);
      return;
    }
    clamp();
    window.addEventListener("resize", clamp);
    return () => window.removeEventListener("resize", clamp);
  }, [open, clamp]);

  useEffect(() => {
    if (!open) return;

    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") setOpen(false);
    };
    // Pointer, not click: a tap that starts outside should dismiss before it
    // has a chance to activate whatever it landed on.
    const onPointer = (e: PointerEvent) => {
      if (!ref.current?.contains(e.target as Node)) setOpen(false);
    };

    document.addEventListener("keydown", onKey);
    document.addEventListener("pointerdown", onPointer);

    return () => {
      document.removeEventListener("keydown", onKey);
      document.removeEventListener("pointerdown", onPointer);
    };
  }, [open]);

  return (
    <span
      ref={ref}
      className="relative inline-flex align-middle"
      onMouseEnter={() => setOpen(true)}
      onMouseLeave={() => setOpen(false)}
    >
      <button
        // Load-bearing: this lives inside <form>, and a bare <button> defaults
        // to submit — hovering for help would save the event.
        type="button"
        aria-label={label}
        aria-expanded={open}
        aria-describedby={open ? id : undefined}
        onClick={() => setOpen((v) => !v)}
        onFocus={() => setOpen(true)}
        onBlur={() => setOpen(false)}
        className="text-muted-foreground transition-colors hover:text-foreground focus-visible:text-foreground focus-visible:outline-none"
      >
        <Info className="h-4 w-4" />
      </button>

      {open && (
        <span
          ref={panelRef}
          id={id}
          role="tooltip"
          // Width capped to the viewport minus both gutters, so the clamp has
          // something it can actually fit; the shift then slides it in.
          style={{ transform: shift ? `translateX(${shift}px)` : undefined }}
          className={cn(
            "absolute top-full z-20 mt-2 w-64 max-w-[calc(100vw-1.5rem)] rounded-lg border border-border bg-popover p-3 text-xs font-normal leading-relaxed text-muted-foreground shadow-[var(--shadow-md)]",
            align === "end" ? "right-0" : "left-0"
          )}
        >
          {children}
        </span>
      )}
    </span>
  );
}
