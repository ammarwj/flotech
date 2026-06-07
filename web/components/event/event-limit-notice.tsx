"use client";

import Link from "next/link";
import { TriangleAlert, ArrowUpRight, ListChecks } from "lucide-react";

import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";

/**
 * Shown when an org has hit its plan's active-event cap. Explains the limit and
 * gives two concrete next steps: upgrade the plan, or free a slot by managing
 * existing events.
 */
export function EventLimitNotice({
  planName,
  limit,
}: {
  planName?: string;
  /** Known active-event cap; null when the exact number isn't available. */
  limit: number | null;
}) {
  return (
    <Card className="max-w-2xl border-[color-mix(in_srgb,var(--warning)_45%,transparent)] bg-[color-mix(in_srgb,var(--warning)_8%,transparent)] p-6">
      <div className="flex items-start gap-3">
        <span className="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-[color-mix(in_srgb,var(--warning)_16%,transparent)] text-[var(--warning)]">
          <TriangleAlert className="h-5 w-5" />
        </span>
        <div>
          <h3 className="text-base font-bold" style={{ fontFamily: "var(--font-display)" }}>
            Batas event aktif tercapai
          </h3>
          <p className="mt-1 text-sm text-muted-foreground">
            {limit !== null ? (
              <>
                Paket{planName ? ` ${planName}` : ""} kamu mengizinkan{" "}
                <span className="font-semibold text-foreground">
                  {limit} event aktif
                </span>
                .{" "}
              </>
            ) : (
              <>Paket{planName ? ` ${planName}` : ""} kamu sudah mencapai batas event aktif. </>
            )}
            Selesaikan atau batalkan event yang berjalan untuk membuka slot, atau
            upgrade paket untuk menambah kapasitas.
          </p>
        </div>
      </div>

      <div className="mt-5 flex flex-col gap-2 sm:flex-row">
        <Button asChild>
          <Link href="/organizer/upgrade">
            <ArrowUpRight className="h-4 w-4" />
            Upgrade paket
          </Link>
        </Button>
        <Button asChild variant="outline">
          <Link href="/organizer/events">
            <ListChecks className="h-4 w-4" />
            Kelola event
          </Link>
        </Button>
      </div>
    </Card>
  );
}
