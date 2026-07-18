"use client";

import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from "react";
import { createPortal } from "react-dom";
import { Menu, X } from "lucide-react";

import { cn } from "@/lib/utils";

/**
 * The mobile chrome shared by the dashboard and the public header: one
 * hamburger, one slide-in panel, so the two surfaces behave and look identical.
 *
 * They differ only in where the desktop layout takes over — the dashboard swaps
 * to a sidebar at `md`, the landing nav shows its links at 940px — which is what
 * `closeAbove` is for.
 */

/**
 * Dismisses the sheet *with* its exit animation. Anything inside the panel that
 * closes it (a nav link, the mode switcher) must use this rather than the
 * caller's own `onClose`, which unmounts the panel on the spot and would make
 * those paths the only ones that snap shut.
 */
const SheetCloseContext = createContext<() => void>(() => {});

export function useSheetClose(): () => void {
  return useContext(SheetCloseContext);
}

/** The hamburger that opens a `MobileSheet`. */
export function MobileMenuButton({
  onClick,
  expanded,
  className,
}: {
  onClick: () => void;
  expanded: boolean;
  className?: string;
}) {
  return (
    <button
      type="button"
      // `theme-toggle` is the 40px bordered square used by the theme button next
      // to it; sharing it keeps the two controls the same size and weight.
      className={cn("theme-toggle", className)}
      onClick={onClick}
      aria-label="Menu"
      aria-expanded={expanded}
    >
      <Menu className="h-5 w-5" />
    </button>
  );
}

export function MobileSheet({
  label,
  header,
  footer,
  closeAbove,
  onClose,
  children,
}: {
  /** Accessible name of the dialog. */
  label: string;
  /** Top block, above the divider — identity when someone is signed in. */
  header: ReactNode;
  /** Pinned to the bottom; the primary actions live here. */
  footer?: ReactNode;
  /** Viewport width (px) at which the desktop layout takes over. */
  closeAbove: number;
  onClose: () => void;
  children: ReactNode;
}) {
  const [closing, setClosing] = useState(false);

  // Unmounting happens on animationend, not here, so the exit animation gets to
  // finish. Guarded so a second trigger (Escape during the exit) is a no-op.
  const close = useCallback(() => setClosing(true), []);

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => e.key === "Escape" && close();
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [close]);

  // Widening past the breakpoint hides the hamburger, which would otherwise
  // leave the panel open with no control that owns it. Unmount immediately —
  // the layout has already changed, so sliding out would just be noise.
  useEffect(() => {
    const mq = window.matchMedia(`(min-width: ${closeAbove}px)`);
    const onChange = () => mq.matches && onClose();
    onChange();
    mq.addEventListener("change", onChange);
    return () => mq.removeEventListener("change", onChange);
  }, [closeAbove, onClose]);

  // Portalled to <body> because both headers carry a backdrop-filter, and that
  // makes the header the containing block for fixed descendants — `inset-0`
  // would resolve to the ~375×64 bar instead of the viewport, clipping the
  // overlay to the top of the screen.
  return createPortal(
    // Above the dashboard's bottom tab bar (z-40) and either header (z-30/z-50).
    <div
      className="sheet-overlay fixed inset-0 z-[60] bg-black/50"
      data-closing={closing}
      onClick={close}
    >
      <div
        role="dialog"
        aria-modal="true"
        aria-label={label}
        data-closing={closing}
        onClick={(e) => e.stopPropagation()}
        // Only the panel's own animationend unmounts: animationend bubbles, so
        // without the target check any animated child would tear the sheet down.
        onAnimationEnd={(e) => {
          if (closing && e.target === e.currentTarget) onClose();
        }}
        className="sheet-panel ml-auto flex h-full w-[min(320px,85vw)] flex-col border-l border-border bg-card shadow-[var(--shadow-lg)]"
      >
        <div className="flex items-start gap-3 border-b border-border p-5">
          <div className="min-w-0 flex-1">{header}</div>
          <button
            onClick={close}
            className="rounded-md p-1 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
            aria-label="Tutup"
          >
            <X className="h-4 w-4" />
          </button>
        </div>

        <SheetCloseContext.Provider value={close}>
          <div className="grid content-start gap-5 overflow-y-auto p-5">{children}</div>
          {footer && <div className="mt-auto grid gap-2 border-t border-border p-5">{footer}</div>}
        </SheetCloseContext.Provider>
      </div>
    </div>,
    document.body,
  );
}
