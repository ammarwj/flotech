"use client";

import { ChevronDown } from "lucide-react";

import { cn } from "@/lib/utils";

/**
 * A section that folds away, built on native `<details>`.
 *
 * Native rather than a `useState` toggle, and that is the whole reason this is
 * worth a file: `<details>` is keyboard-operable, announced as expandable, and
 * findable by the browser's own in-page search — Chrome and Safari open a
 * closed `<details>` to reveal a match, which a `hidden` div defeats. A page
 * whose details are collapsed must not become a page whose details cannot be
 * searched.
 *
 * `defaultOpen` and not `open`: nothing here needs to drive the state from
 * outside, and a controlled `<details>` has to re-implement the toggle it
 * already has.
 *
 * The chevron is the only motion, and it is a response to a click — the kind
 * the design guidance keeps, unlike an entrance that plays at nobody.
 */
export function Disclosure({
  title,
  /** Shown beside the title while closed — the gist, so folding costs nothing. */
  summary,
  defaultOpen = false,
  className,
  children,
}: {
  title: string;
  summary?: React.ReactNode;
  defaultOpen?: boolean;
  className?: string;
  children: React.ReactNode;
}) {
  return (
    <details
      open={defaultOpen}
      className={cn("group border-t border-border first:border-t-0", className)}
    >
      <summary
        className={cn(
          // `list-none` plus the WebKit pseudo-element: Safari draws its own
          // triangle and ignores the first on its own.
          "flex cursor-pointer list-none items-center gap-3 py-3 text-sm",
          "[&::-webkit-details-marker]:hidden",
          "hover:text-foreground focus-visible:outline-none focus-visible:ring-2",
          "focus-visible:ring-[var(--brand-600)] focus-visible:ring-offset-2 rounded-sm"
        )}
      >
        <ChevronDown
          className="h-4 w-4 shrink-0 text-muted-foreground transition-transform duration-150 group-open:rotate-180 motion-reduce:transition-none"
          aria-hidden
        />
        <span className="font-medium">{title}</span>
        {/* Pushed right and dropped when open: once the section is showing, a
            summary of it is the same information twice. */}
        {summary !== undefined && (
          <span className="ml-auto text-muted-foreground group-open:hidden">{summary}</span>
        )}
      </summary>
      <div className="pb-4">{children}</div>
    </details>
  );
}
