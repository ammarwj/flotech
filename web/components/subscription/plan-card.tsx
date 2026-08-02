"use client";

import { Check, X } from "lucide-react";

import { formatPlanFeature, getPlanColor } from "@/lib/plan";
import { rupiah } from "@/lib/labels";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { cn } from "@/lib/utils";
import type { Plan } from "@/types/api";

/**
 * One plan in the pricing grid. The feature list is data-driven from
 * `plan.feature_details` (see CLAUDE.md) — never hardcode labels here.
 *
 * There is no "current plan" any more: a plan belongs to an event, and an
 * organizer can be running a Starter event and a Professional one at once. What
 * a card offers is a purchase, every time.
 */
export function PlanCard({
  plan,
  isPending = false,
  disabled = false,
  onSelect,
}: {
  plan: Plan;
  isPending?: boolean;
  disabled?: boolean;
  onSelect: (plan: Plan) => void;
}) {
  const color = getPlanColor(plan.slug);
  const featured = plan.slug === "pro";

  return (
    <Card
      className={cn(
        "relative flex flex-col p-5",
        featured &&
          "ring-1 ring-[color-mix(in_srgb,var(--brand-600)_50%,transparent)]",
      )}
    >
      {featured && (
        <span className="absolute -top-2.5 left-5 rounded-full bg-[var(--brand-600)] px-2.5 py-0.5 text-[11px] font-bold text-white">
          Populer
        </span>
      )}

      <div className="flex items-center gap-2">
        <span
          className="h-2.5 w-2.5 rounded-full"
          style={{ background: color }}
        />
        <span
          className="font-bold"
          style={{ fontFamily: "var(--font-display)" }}
        >
          {plan.name}
        </span>
      </div>

      <div
        className="mt-3 text-2xl font-extrabold"
        style={{ fontFamily: "var(--font-display)" }}
      >
        {plan.price === 0 ? "Gratis" : rupiah(plan.price)}
        {plan.price > 0 && (
          <span className="text-sm font-medium text-muted-foreground">
            /event
          </span>
        )}
      </div>

      <p className="mt-1 min-h-[36px] text-xs text-muted-foreground">
        {plan.description}
      </p>

      <ul className="mt-4 flex-1 space-y-1.5 text-xs">
        {plan.feature_details?.map((feature) => (
          <li
            key={feature.key}
            title={feature.description ?? undefined}
            className={cn(
              "flex items-start gap-1.5",
              !feature.included && "text-muted-foreground/60",
            )}
          >
            {feature.included ? (
              <Check className="mt-px h-3.5 w-3.5 shrink-0" style={{ color }} />
            ) : (
              <X className="mt-px h-3.5 w-3.5 shrink-0" />
            )}
            <span className={cn(!feature.included && "line-through")}>
              {formatPlanFeature(feature)}
            </span>
          </li>
        ))}
      </ul>

      <Button
        className="mt-4"
        variant={featured ? "default" : "outline"}
        disabled={disabled}
        onClick={() => onSelect(plan)}
      >
        {isPending ? "Memproses…" : "Beli paket"}
      </Button>
    </Card>
  );
}
