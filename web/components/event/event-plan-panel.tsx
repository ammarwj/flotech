"use client";

import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { PackageCheck } from "lucide-react";

import { getPlanOrders } from "@/lib/api/organizations";
import { canUpgrade, getCategoryLimit, getTeamsPerCategoryLimit } from "@/lib/plan";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { PlanUpgradeDialog } from "@/components/subscription/plan-upgrade-dialog";
import type { SportEvent } from "@/types/api";

/**
 * The plan this event runs on, and the way up from it.
 *
 * The order is what an upgrade is raised against, and the event payload only
 * carries the plan — hence the orders query rather than a prop. It is the same
 * `["plan-orders", orgId]` key the billing page uses, so arriving from there
 * costs nothing, and the upgrade dialog invalidates it either way.
 *
 * There is no downgrade control here and none anywhere else: PlanGate refuses a
 * target that grants less, so an event can never lose a feature it is using.
 */
export function EventPlanPanel({ orgId, event }: { orgId: string; event: SportEvent }) {
  const [upgrading, setUpgrading] = useState(false);

  const ordersQuery = useQuery({
    queryKey: ["plan-orders", orgId],
    queryFn: () => getPlanOrders(orgId),
  });

  const order = ordersQuery.data?.find((o) => o.event_id === event.id) ?? null;

  // No plan means a backfilled or hand-made event; there is nothing to raise an
  // upgrade against, and inventing one would be inventing a purchase.
  if (!event.plan) return null;

  // `event.plan` is a PlanSummary — the raw feature map, no `feature_details`.
  // The same two helpers EventForm uses, so the caps a person reads here and the
  // caps the form enforces cannot disagree.
  const categories = getCategoryLimit(event);
  const entries = getTeamsPerCategoryLimit(event);

  return (
    <>
      <Card className="mb-6 flex flex-wrap items-center justify-between gap-4 p-4">
        <div className="flex min-w-0 items-start gap-3">
          <PackageCheck className="mt-0.5 h-5 w-5 shrink-0 text-[var(--brand-600)]" />
          <div className="min-w-0">
            <p className="font-semibold">Paket {event.plan.name}</p>
            <p className="mt-0.5 text-sm text-muted-foreground">
              {categories !== null ? `Maks ${categories} kategori` : "Kategori tanpa batas"}
              {" · "}
              {entries !== null ? `${entries} entri per kategori` : "Entri tanpa batas"}
            </p>
          </div>
        </div>

        {order && canUpgrade(order) && (
          <Button variant="secondary" size="sm" onClick={() => setUpgrading(true)}>
            Naikkan paket
          </Button>
        )}
      </Card>

      {upgrading && order && (
        <PlanUpgradeDialog
          orgId={orgId}
          order={order}
          open
          onOpenChange={(next) => !next && setUpgrading(false)}
        />
      )}
    </>
  );
}
