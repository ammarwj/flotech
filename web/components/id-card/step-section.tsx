import { Check } from "lucide-react";

import { cn } from "@/lib/utils";

/**
 * One numbered step in the print flow.
 *
 * The flow is genuinely sequential — there is no pool of recipients until an
 * event is chosen, and nothing to render until a template is — but it used to
 * be five equal cards stacked in a column, which says nothing about which one
 * to touch first or what happens after. The number is the whole fix; the
 * `done` tick is what lets an organizer see at a glance where they stopped.
 *
 * `muted` dims a step whose prerequisite is unmet rather than hiding it: a step
 * that appears only once you have guessed the step before it is a flow you have
 * to discover twice. The inputs inside still disable themselves — this is
 * presentation, not enforcement.
 */
export function StepSection({
  step,
  title,
  hint,
  done = false,
  muted = false,
  aside,
  children,
}: {
  step: number;
  title: string;
  hint?: string;
  done?: boolean;
  muted?: boolean;
  /** Sits opposite the title — a count, a select-all, a status line. */
  aside?: React.ReactNode;
  children: React.ReactNode;
}) {
  return (
    <section className={cn("transition-opacity", muted && "opacity-55")}>
      <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <span
            className={cn(
              "grid h-7 w-7 shrink-0 place-items-center rounded-full text-xs font-bold",
              done
                ? "bg-[color-mix(in_srgb,var(--success)_16%,transparent)] text-[var(--success)]"
                : "bg-[var(--tint)] text-[var(--brand-600)]"
            )}
          >
            {done ? <Check className="h-3.5 w-3.5" /> : step}
          </span>

          <div className="min-w-0">
            <h2 className="text-sm font-semibold">{title}</h2>
            {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
          </div>
        </div>

        {aside}
      </div>

      {/* Indented under the number on desktop so the column reads as one
          sequence; flush on a phone, where 40px of gutter is a tenth of the
          screen. */}
      <div className="sm:pl-10">{children}</div>
    </section>
  );
}
