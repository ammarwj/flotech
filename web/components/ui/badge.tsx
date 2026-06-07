import * as React from "react";
import { cva, type VariantProps } from "class-variance-authority";

import { cn } from "@/lib/utils";

// Status colors follow PRD §9.5: rounded-full, tinted bg per status.
const badgeVariants = cva(
  "inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold tracking-[0.02em] whitespace-nowrap",
  {
    variants: {
      variant: {
        default: "bg-[var(--tint)] text-[var(--brand-600)]",
        neutral: "bg-[var(--bg-soft)] text-[var(--text-2)]",
        success:
          "bg-[color-mix(in_srgb,var(--success)_14%,transparent)] text-[var(--success)]",
        warning:
          "bg-[color-mix(in_srgb,var(--warning)_16%,transparent)] text-[var(--warning)]",
        danger:
          "bg-[color-mix(in_srgb,var(--danger)_14%,transparent)] text-[var(--danger)]",
        info: "bg-[color-mix(in_srgb,var(--brand-600)_14%,transparent)] text-[var(--brand-600)]",
        outline: "border border-border text-[var(--text-2)]",
      },
    },
    defaultVariants: { variant: "default" },
  }
);

export interface BadgeProps
  extends React.HTMLAttributes<HTMLSpanElement>,
    VariantProps<typeof badgeVariants> {
  dot?: boolean;
}

function Badge({ className, variant, dot, children, ...props }: BadgeProps) {
  return (
    <span className={cn(badgeVariants({ variant }), className)} {...props}>
      {dot && (
        <span
          className="inline-block h-1.5 w-1.5 rounded-full bg-current"
          aria-hidden
        />
      )}
      {children}
    </span>
  );
}

export { Badge, badgeVariants };
