"use client";

import Link from "next/link";
import { Clock } from "lucide-react";

import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";

/**
 * Tells an organizer their manual plan payment is with a super admin.
 *
 * Deliberately separate from ManualModeBanner, which answers a different
 * question ("your sales run on manual transfer") for a different audience
 * (every org, plan or not). Merging them would produce one card whose other
 * half is always irrelevant.
 *
 * The flag rides on the organization payload rather than its own query because
 * this renders on every organizer page, `operator` members included — and they
 * get a 403 from the org.admin-guarded /plan-orders endpoint.
 */
export function PlanPaymentPendingBanner() {
  const { org } = useActiveOrg();

  // No `!org.plan` guard any more: an organization has no plan of its own, and
  // an organizer with three running events can still be waiting on a fourth.
  if (!org || !org.plan_payment_awaiting_verification) return null;

  return (
    <Card
      className="mb-4 flex flex-col gap-3 p-3 sm:mb-5 sm:flex-row sm:items-center sm:p-4"
      style={{
        borderColor: `color-mix(in srgb, var(--brand-600) 40%, transparent)`,
        background: `color-mix(in srgb, var(--brand-600) 6%, transparent)`,
      }}
    >
      <div className="flex min-w-0 flex-1 items-start gap-2.5 sm:gap-3">
        <Clock className="mt-0.5 h-4 w-4 shrink-0 text-[var(--brand-600)] sm:h-5 sm:w-5" />
        <div className="min-w-0 flex-1">
          <p className="text-sm font-semibold sm:text-base">
            Pembayaran paketmu sedang diverifikasi
          </p>
          <p className="mt-0.5 text-sm text-muted-foreground">
            Admin flo-event sedang memeriksa bukti transfermu. Paketnya siap dipakai untuk
            membuat event begitu pembayaran diterima.
          </p>
        </div>
      </div>
      <Button asChild size="sm" variant="outline" className="w-full shrink-0 sm:w-auto">
        <Link href="/organizer/billing">Lihat status</Link>
      </Button>
    </Card>
  );
}
