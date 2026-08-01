"use client";

import Link from "next/link";
import { Lock } from "lucide-react";

import { Button } from "@/components/ui/button";

/**
 * A feature this event's plan does not include.
 *
 * Deliberately says "paket event ini", not "paketmu": the organizer may well
 * have the feature on another event running right now, and telling them to
 * upgrade something they already own reads as a bug. What they can do is buy a
 * bigger plan for the *next* event — the current one's plan is locked.
 */
export function PlanFeatureNotice({ feature }: { feature: string }) {
  return (
    <div className="flex flex-col items-start gap-3 rounded-lg border border-dashed border-border p-5 text-sm sm:flex-row sm:items-center">
      <span className="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-[var(--bg-soft)] text-muted-foreground">
        <Lock className="h-4 w-4" />
      </span>
      <p className="flex-1 text-muted-foreground">
        <span className="font-semibold text-foreground">{feature}</span> tidak termasuk dalam paket
        event ini. Paket terkunci ke event sejak dibuat — pilih paket lebih tinggi untuk event
        berikutnya.
      </p>
      <Button asChild size="sm" variant="outline">
        <Link href="/organizer/plans">Lihat paket</Link>
      </Button>
    </div>
  );
}
