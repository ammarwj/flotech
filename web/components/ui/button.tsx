import * as React from "react";
import { Slot } from "@radix-ui/react-slot";
import { cva, type VariantProps } from "class-variance-authority";

import { cn } from "@/lib/utils";

const buttonVariants = cva(
  "inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background disabled:pointer-events-none disabled:opacity-50 [&_svg]:size-4 [&_svg]:shrink-0",
  {
    variants: {
      variant: {
        default: "bg-primary text-white! hover:bg-primary/90",
        destructive: "bg-destructive text-white! hover:bg-destructive/90",
        outline: "border border-input bg-background hover:bg-accent hover:text-accent-foreground",
        secondary: "bg-secondary text-secondary-foreground hover:bg-secondary/80",
        ghost: "hover:bg-accent hover:text-accent-foreground",
        link: "text-primary underline-offset-4 hover:underline",
      },
      size: {
        default: "h-10 px-5 py-2",
        sm: "h-9 rounded-md px-3",
        lg: "h-12 rounded-md px-6 text-base",
        icon: "h-10 w-10",
      },
    },
    defaultVariants: {
      variant: "default",
      size: "default",
    },
  }
);

export interface ButtonProps
  extends React.ButtonHTMLAttributes<HTMLButtonElement>,
    VariantProps<typeof buttonVariants> {
  asChild?: boolean;
}

const Button = React.forwardRef<HTMLButtonElement, ButtonProps>(
  ({ className, variant, size, asChild = false, type, ...props }, ref) => {
    const Comp = asChild ? Slot : "button";
    return (
      <Comp
        className={cn(buttonVariants({ variant, size, className }))}
        ref={ref}
        /**
         * `type="button"` unless the caller asks otherwise.
         *
         * HTML defaults a <button> inside a form to `submit`, which is the
         * wrong default for a component used 437 times and meant to submit in
         * about 26 of them: any button that lands inside a form — often because
         * something above it was moved, not because it was written there —
         * silently starts submitting it. That is exactly what happened when the
         * race setup panel moved inside EventForm: pressing "Atur Periode"
         * saved the whole event and toasted "Perubahan tersimpan".
         *
         * Safe to flip: every submit button in this codebase already says
         * `type="submit"`, and every other button has an `onClick` — none were
         * relying on the implicit default.
         *
         * Under `asChild` the child is usually an <a> or a <Link>, where `type`
         * means a content-type hint rather than a button role — so nothing is
         * defaulted there. A `type` the caller passed is still forwarded, since
         * it was destructured out and would otherwise be dropped.
         */
        {...(asChild ? (type ? { type } : {}) : { type: type ?? "button" })}
        {...props}
      />
    );
  }
);
Button.displayName = "Button";

export { Button, buttonVariants };
