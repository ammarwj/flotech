"use client";

import { Check, X } from "lucide-react";

import { formatPlanFeature, getYearlyDiscount, getYearlyListPrice } from "@/lib/plan";
import { rupiah } from "@/lib/labels";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { cn } from "@/lib/utils";
import type { Plan } from "@/types/api";

export type BillingCycle = "monthly" | "yearly";

const PLAN_COLORS: Record<string, string> = {
  free: "var(--plan-free)",
  starter: "var(--plan-starter)",
  pro: "var(--plan-pro)",
  professional: "var(--plan-professional)",
};

/** `discount` is the best saving across plans (see getMaxYearlyDiscount); 0 hides the badge. */
export function BillingCycleToggle({
  cycle,
  onChange,
  discount = 0,
}: {
  cycle: BillingCycle;
  onChange: (cycle: BillingCycle) => void;
  discount?: number;
}) {
  return (
    <div className="mb-5 inline-flex items-center gap-1 rounded-full border border-border bg-[var(--surface)] p-1 text-sm font-semibold">
      {(["monthly", "yearly"] as const).map((c) => (
        <button
          key={c}
          type="button"
          onClick={() => onChange(c)}
          className={cn(
            "flex items-center gap-1.5 rounded-full px-4 py-1.5 transition-colors",
            cycle === c
              ? "bg-[var(--brand-600)] text-white"
              : "text-muted-foreground hover:text-foreground"
          )}
        >
          {c === "monthly" ? "Bulanan" : "Tahunan"}
          {c === "yearly" && discount > 0 && (
            <span
              className={cn(
                "rounded-full px-1.5 py-0.5 text-[11px] font-bold",
                cycle === "yearly"
                  ? "bg-white/20 text-white"
                  : "bg-[var(--tint)] text-[var(--brand-600)]"
              )}
            >
              Hemat {discount}%
            </span>
          )}
        </button>
      ))}
    </div>
  );
}

/**
 * One plan in the pricing grid. The feature list is data-driven from
 * `plan.feature_details` (see CLAUDE.md) — never hardcode labels here.
 * `isCurrent` marks the org's active plan; onboarding leaves it false since
 * the org has no plan yet.
 */
export function PlanCard({
  plan,
  cycle,
  isCurrent = false,
  isPending = false,
  disabled = false,
  onSelect,
}: {
  plan: Plan;
  cycle: BillingCycle;
  isCurrent?: boolean;
  isPending?: boolean;
  disabled?: boolean;
  onSelect: (plan: Plan) => void;
}) {
  const price = cycle === "yearly" ? plan.price_yearly : plan.price_monthly;
  const color = PLAN_COLORS[plan.slug] ?? "var(--brand-600)";
  const featured = plan.slug === "pro";

  const discount = getYearlyDiscount(plan);
  const showSaving = cycle === "yearly" && discount > 0;

  return (
    <Card
      className={cn(
        "relative flex flex-col p-5",
        isCurrent
          ? "ring-1 ring-[var(--brand-600)]"
          : featured && "ring-1 ring-[color-mix(in_srgb,var(--brand-600)_50%,transparent)]"
      )}
    >
      {(isCurrent || featured) && (
        <span className="absolute -top-2.5 left-5 rounded-full bg-[var(--brand-600)] px-2.5 py-0.5 text-[11px] font-bold text-white">
          {isCurrent ? "Paket aktif" : "Populer"}
        </span>
      )}

      <div className="flex items-center gap-2">
        <span className="h-2.5 w-2.5 rounded-full" style={{ background: color }} />
        <span className="font-bold" style={{ fontFamily: "var(--font-display)" }}>
          {plan.name}
        </span>
      </div>

      <div className="mt-3 min-h-[18px] text-xs">
        {showSaving && (
          <span className="text-muted-foreground line-through">
            {rupiah(getYearlyListPrice(plan))}
          </span>
        )}
      </div>

      <div className="text-2xl font-extrabold" style={{ fontFamily: "var(--font-display)" }}>
        {price === 0 ? "Gratis" : rupiah(price)}
        {price > 0 && (
          <span className="text-sm font-medium text-muted-foreground">
            /{cycle === "yearly" ? "thn" : "bln"}
          </span>
        )}
      </div>

      {showSaving && (
        <span
          className="mt-1.5 w-fit rounded-full px-2 py-0.5 text-[11px] font-bold"
          style={{
            background: `color-mix(in srgb, ${color} 14%, transparent)`,
            color,
          }}
        >
          Hemat {discount}%
        </span>
      )}

      <p className="mt-1 min-h-[36px] text-xs text-muted-foreground">{plan.description}</p>

      <ul className="mt-4 flex-1 space-y-1.5 text-xs">
        {plan.feature_details?.map((feature) => (
          <li
            key={feature.key}
            title={feature.description ?? undefined}
            className={cn(
              "flex items-start gap-1.5",
              !feature.included && "text-muted-foreground/60"
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
        variant={isCurrent ? "outline" : featured ? "default" : "outline"}
        disabled={isCurrent || disabled}
        onClick={() => onSelect(plan)}
      >
        {isCurrent ? (
          <>
            <Check className="h-4 w-4" />
            Paket aktif
          </>
        ) : isPending ? (
          "Memproses…"
        ) : (
          "Pilih paket"
        )}
      </Button>
    </Card>
  );
}
