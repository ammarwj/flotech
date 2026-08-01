"use client";

import Link from "next/link";
import { PackageCheck } from "lucide-react";

import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";

/**
 * Tells an organizer they are holding a plan they have paid for and not used.
 *
 * This is the surface that stops an abandoned checkout from being money nobody
 * ever sees again. A credit never expires — the organizer paid, and taking it
 * back would be taking money — so without something saying so it would simply
 * sit in the orders list, invisible.
 *
 * Like PlanPaymentPendingBanner, the count rides on the organization payload
 * rather than its own query: this renders on every organizer page, `operator`
 * members included, and they get a 403 from the org.admin-guarded /plan-orders
 * endpoint.
 */
export function UnconsumedPlanBanner() {
  const { org } = useActiveOrg();

  const count = org?.unconsumed_plan_orders_count ?? 0;
  if (count === 0) return null;

  return (
    <Card
      className="mb-4 flex flex-col gap-3 p-3 sm:mb-5 sm:flex-row sm:items-center sm:p-4"
      style={{
        borderColor: `color-mix(in srgb, var(--success) 40%, transparent)`,
        background: `color-mix(in srgb, var(--success) 6%, transparent)`,
      }}
    >
      <div className="flex min-w-0 flex-1 items-start gap-2.5 sm:gap-3">
        <PackageCheck className="mt-0.5 h-4 w-4 shrink-0 text-[var(--success)] sm:h-5 sm:w-5" />
        <div className="min-w-0 flex-1">
          <p className="text-sm font-semibold sm:text-base">
            {count === 1 ? "Kamu punya 1 paket yang belum dipakai" : `Kamu punya ${count} paket yang belum dipakai`}
          </p>
          <p className="mt-0.5 text-sm text-muted-foreground">
            Paket yang sudah dibayar menunggu satu event untuk dipakai. Tidak ada masa berlaku —
            buat eventnya kapan saja.
          </p>
        </div>
      </div>
      <Button asChild size="sm" className="w-full shrink-0 sm:w-auto">
        <Link href="/organizer/events/new">Buat event</Link>
      </Button>
    </Card>
  );
}
