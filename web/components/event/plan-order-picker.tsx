"use client";

import { Card } from "@/components/ui/card";
import { rupiah } from "@/lib/labels";
import { getPlanColor } from "@/lib/plan";
import { cn } from "@/lib/utils";
import type { EventPlanOrder } from "@/types/api";

/**
 * Which paid credit this event will be created on.
 *
 * Only rendered when the organizer holds more than one. It matters because the
 * plan locks to the event the moment it is created and there is no mid-event
 * upgrade: spending a Starter on the big tournament because it happened to be
 * bought first is a mistake with no undo.
 */
export function PlanOrderPicker({
  credits,
  activeId,
  onChange,
}: {
  credits: EventPlanOrder[];
  activeId?: string;
  onChange: (id: string) => void;
}) {
  return (
    <div className="mb-6 max-w-2xl">
      <p className="mb-2 text-sm font-semibold">Paket yang dipakai</p>
      <div className="grid gap-2 sm:grid-cols-2">
        {credits.map((credit) => {
          const active = credit.id === activeId;
          const color = getPlanColor(credit.plan?.slug ?? "");

          return (
            <Card
              key={credit.id}
              role="radio"
              tabIndex={0}
              aria-checked={active}
              onClick={() => onChange(credit.id)}
              onKeyDown={(e) => {
                if (e.key === "Enter" || e.key === " ") {
                  e.preventDefault();
                  onChange(credit.id);
                }
              }}
              className={cn(
                "cursor-pointer p-3 transition-colors",
                active
                  ? "ring-2 ring-[var(--brand-600)]"
                  : "hover:border-[var(--border-strong)]"
              )}
            >
              <div className="flex items-center gap-2">
                <span className="h-2.5 w-2.5 rounded-full" style={{ background: color }} />
                <span className="font-semibold">{credit.plan?.name ?? "Paket dihapus"}</span>
              </div>
              <p className="mt-1 text-xs text-muted-foreground">
                {rupiah(credit.amount)} &middot; {credit.invoice_number ?? "—"}
              </p>
            </Card>
          );
        })}
      </div>
      <p className="mt-2 text-xs text-muted-foreground">
        Paket terkunci ke event ini begitu dibuat dan tidak bisa ditukar.
      </p>
    </div>
  );
}
