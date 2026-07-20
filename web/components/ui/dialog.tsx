"use client";

import * as React from "react";
import * as DialogPrimitive from "@radix-ui/react-dialog";
import { X } from "lucide-react";
import type { LucideIcon } from "lucide-react";

import { cn } from "@/lib/utils";

/**
 * The dialog shell, as the twelve hand-rolled dialogs in this app already draw
 * it. The class strings below are the source of truth — `alert-dialog.tsx`
 * imports them so the two can't drift, and so a thirteenth variation of
 * "overlay + panel + header" never gets invented.
 *
 * Radix brings what none of the hand-rolled ones have: a focus trap, focus
 * restored to the trigger, scroll lock, aria wiring, and a portal to <body>.
 * That last one is not cosmetic — the dashboard header and the event form's
 * sticky footer both carry `backdrop-filter`, which makes an element the
 * containing block for `position: fixed` descendants. A dialog rendered inside
 * one would resolve `inset-0` against the footer instead of the viewport
 * (mobile-sheet.tsx documents hitting exactly that).
 */

export type DialogTone = "default" | "danger";

/** Centred card (default) or a bottom sheet that centres from sm up. */
export type DialogPlacement = "center" | "sheet";

export const dialogOverlay: Record<DialogPlacement, string> = {
  center:
    "fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 dialog-overlay",
  sheet:
    "fixed inset-0 z-50 flex items-end justify-center bg-black/60 p-0 backdrop-blur-sm dialog-overlay sm:items-center sm:p-4",
};

export const dialogPanel: Record<DialogPlacement, string> = {
  center:
    "flex max-h-[85vh] w-full max-w-lg flex-col overflow-hidden rounded-xl border border-border bg-card shadow-[var(--shadow-lg)] dialog-panel",
  sheet:
    "flex max-h-[85vh] w-full max-w-lg flex-col overflow-hidden rounded-t-2xl border border-border bg-card shadow-[var(--shadow-lg)] dialog-panel-sheet sm:rounded-xl",
};

export const dialogHeaderRow = "flex items-start gap-3 border-b border-border p-5";
export const dialogBodyClass = "grid gap-4 overflow-y-auto p-5";
export const dialogTitleClass = "text-base font-bold";
export const dialogDescriptionClass = "mt-0.5 text-sm text-muted-foreground";
export const dialogCloseClass =
  "rounded-md p-1 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground";

/**
 * Footer stacks on a phone and reverses, so the primary reads first while the
 * cancel stays first in the DOM — which is what keeps Radix's initial focus and
 * the tab order correct.
 */
export const dialogFooterClass =
  "flex flex-col-reverse gap-2 border-t border-border p-4 sm:flex-row sm:items-center sm:justify-end";

export const dialogIconChip: Record<DialogTone, string> = {
  default:
    "grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-[var(--tint)] text-[var(--brand-600)]",
  danger:
    "grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-[color-mix(in_srgb,var(--danger)_12%,transparent)] text-[var(--danger)] ring-1 ring-[color-mix(in_srgb,var(--danger)_22%,transparent)]",
};

/** "What you'll lose", as a tinted strip rather than another paragraph. */
export const dialogConsequences: Record<DialogTone, string> = {
  default: "rounded-md border border-border bg-[var(--bg-soft)] px-3 py-2 text-xs text-muted-foreground",
  danger:
    "rounded-md border border-[color-mix(in_srgb,var(--danger)_25%,transparent)] bg-[color-mix(in_srgb,var(--danger)_7%,transparent)] px-3 py-2 text-xs text-[var(--danger)]",
};

export const Dialog = DialogPrimitive.Root;
export const DialogTrigger = DialogPrimitive.Trigger;
export const DialogClose = DialogPrimitive.Close;
export const DialogTitle = DialogPrimitive.Title;
export const DialogDescription = DialogPrimitive.Description;

/**
 * Content nested inside Overlay, rather than the shadcn default of positioning
 * Content itself with `fixed left-1/2 top-1/2 -translate-*`. Nesting is what
 * reproduces the existing shell exactly — the `p-4` viewport gutter and the
 * `max-h-[85vh]` behaviour both come from the overlay being the flex parent.
 */
export const DialogContent = React.forwardRef<
  React.ElementRef<typeof DialogPrimitive.Content>,
  React.ComponentPropsWithoutRef<typeof DialogPrimitive.Content> & {
    placement?: DialogPlacement;
  }
>(({ className, children, placement = "center", ...props }, ref) => (
  <DialogPrimitive.Portal>
    <DialogPrimitive.Overlay className={dialogOverlay[placement]}>
      <DialogPrimitive.Content
        ref={ref}
        className={cn(dialogPanel[placement], className)}
        {...props}
      >
        {children}
      </DialogPrimitive.Content>
    </DialogPrimitive.Overlay>
  </DialogPrimitive.Portal>
));
DialogContent.displayName = "DialogContent";

/**
 * Header as props, not children.
 *
 * A deliberate departure from shadcn's `DialogHeader > DialogTitle +
 * DialogDescription`: this app's header is a three-column row (icon chip / text
 * / close), not a stacked column. Locking the shape here is what stops the next
 * dialog from inventing its own.
 */
export function DialogHeader({
  icon: Icon,
  title,
  description,
  tone = "default",
  showClose = true,
  titleId,
  descriptionId,
}: {
  icon?: LucideIcon;
  title: React.ReactNode;
  description?: React.ReactNode;
  tone?: DialogTone;
  showClose?: boolean;
  titleId?: string;
  descriptionId?: string;
}) {
  return (
    <div className={dialogHeaderRow}>
      {Icon && (
        <span className={dialogIconChip[tone]}>
          <Icon className="h-5 w-5" />
        </span>
      )}
      <div className="min-w-0 flex-1">
        <DialogPrimitive.Title
          id={titleId}
          className={dialogTitleClass}
          style={{ fontFamily: "var(--font-display)" }}
        >
          {title}
        </DialogPrimitive.Title>
        {description && (
          <DialogPrimitive.Description id={descriptionId} className={dialogDescriptionClass}>
            {description}
          </DialogPrimitive.Description>
        )}
      </div>
      {showClose && (
        <DialogPrimitive.Close className={dialogCloseClass} aria-label="Tutup">
          <X className="h-4 w-4" />
        </DialogPrimitive.Close>
      )}
    </div>
  );
}

export function DialogBody({
  className,
  ...props
}: React.HTMLAttributes<HTMLDivElement>) {
  return <div className={cn(dialogBodyClass, className)} {...props} />;
}

export function DialogFooter({
  className,
  ...props
}: React.HTMLAttributes<HTMLDivElement>) {
  return <div className={cn(dialogFooterClass, className)} {...props} />;
}
