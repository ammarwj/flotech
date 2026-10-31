import { AlertCircle } from "lucide-react";

/**
 * One field's validation message, under the field it belongs to.
 *
 * Spelled once here because the shape was already copied into `event-form.tsx`
 * and `bank-account-form.tsx`, and a form that renders its refusals in a
 * slightly different red is a form people read as a different kind of problem.
 *
 * Renders nothing without a message, so a call site can pass the lookup
 * straight through without a conditional of its own.
 */
export function FieldError({ message }: { message?: string }) {
  if (!message) return null;

  return (
    <p className="flex items-center gap-1.5 text-xs text-destructive">
      <AlertCircle className="h-3.5 w-3.5 shrink-0" />
      {message}
    </p>
  );
}
