"use client";

import Link from "next/link";
import { ArrowUpRight, Lock } from "lucide-react";

import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";

/**
 * Shown in place of a feature the org's plan doesn't include — the proactive
 * half of plan gating, the 403 handler being the reactive half.
 *
 * Same shape as EventLimitNotice, but for a boolean feature rather than a cap.
 */
export function FeatureLockedNotice({
  title,
  description,
  planName,
}: {
  title: string;
  description: string;
  planName?: string;
}) {
  return (
    <Card className="max-w-2xl p-6">
      <div className="flex items-start gap-3">
        <span className="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-[var(--tint)] text-[var(--brand-600)]">
          <Lock className="h-5 w-5" />
        </span>
        <div>
          <h3 className="text-base font-bold" style={{ fontFamily: "var(--font-display)" }}>
            {title}
          </h3>
          <p className="mt-1 text-sm text-muted-foreground">
            {description}
            {planName ? ` Paket ${planName} kamu belum mencakupnya.` : ""}
          </p>
        </div>
      </div>

      <div className="mt-5">
        <Button asChild>
          <Link href="/organizer/upgrade">
            <ArrowUpRight className="h-4 w-4" />
            Upgrade paket
          </Link>
        </Button>
      </div>
    </Card>
  );
}
