"use client";

import * as React from "react";
import * as AlertDialogPrimitive from "@radix-ui/react-alert-dialog";
import type { LucideIcon } from "lucide-react";

import { cn } from "@/lib/utils";
import {
  dialogBodyClass,
  dialogDescriptionClass,
  dialogFooterClass,
  dialogHeaderRow,
  dialogIconChip,
  dialogOverlay,
  dialogPanel,
  dialogTitleClass,
  type DialogPlacement,
  type DialogTone,
} from "./dialog";

/**
 * Same shell as {@link Dialog}, on Radix's alert-dialog instead.
 *
 * The difference is behavioural, and it is the reason both packages are here:
 * an alert dialog refuses outside-click dismissal and puts initial focus on
 * Cancel. For a destructive confirm that matters — a stray tap on the backdrop
 * must not silently abandon the decision, and Enter must not fire the
 * destructive action. Anything containing a form field wants the plain Dialog.
 */

export const AlertDialog = AlertDialogPrimitive.Root;
export const AlertDialogTrigger = AlertDialogPrimitive.Trigger;
export const AlertDialogAction = AlertDialogPrimitive.Action;
export const AlertDialogCancel = AlertDialogPrimitive.Cancel;

export const AlertDialogContent = React.forwardRef<
  React.ElementRef<typeof AlertDialogPrimitive.Content>,
  React.ComponentPropsWithoutRef<typeof AlertDialogPrimitive.Content> & {
    placement?: DialogPlacement;
  }
>(({ className, children, placement = "center", ...props }, ref) => (
  <AlertDialogPrimitive.Portal>
    <AlertDialogPrimitive.Overlay className={dialogOverlay[placement]}>
      <AlertDialogPrimitive.Content
        ref={ref}
        className={cn(dialogPanel[placement], className)}
        {...props}
      >
        {children}
      </AlertDialogPrimitive.Content>
    </AlertDialogPrimitive.Overlay>
  </AlertDialogPrimitive.Portal>
));
AlertDialogContent.displayName = "AlertDialogContent";

export function AlertDialogHeader({
  icon: Icon,
  title,
  description,
  tone = "default",
}: {
  icon?: LucideIcon;
  title: React.ReactNode;
  description?: React.ReactNode;
  tone?: DialogTone;
}) {
  return (
    <div className={dialogHeaderRow}>
      {Icon && (
        <span className={dialogIconChip[tone]}>
          <Icon className="h-5 w-5" />
        </span>
      )}
      <div className="min-w-0 flex-1">
        <AlertDialogPrimitive.Title
          className={dialogTitleClass}
          style={{ fontFamily: "var(--font-display)" }}
        >
          {title}
        </AlertDialogPrimitive.Title>
        {description && (
          <AlertDialogPrimitive.Description className={dialogDescriptionClass}>
            {description}
          </AlertDialogPrimitive.Description>
        )}
      </div>
    </div>
  );
}

export function AlertDialogBody({
  className,
  ...props
}: React.HTMLAttributes<HTMLDivElement>) {
  return <div className={cn(dialogBodyClass, className)} {...props} />;
}

export function AlertDialogFooter({
  className,
  ...props
}: React.HTMLAttributes<HTMLDivElement>) {
  return <div className={cn(dialogFooterClass, className)} {...props} />;
}
