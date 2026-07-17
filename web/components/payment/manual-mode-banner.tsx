"use client";

import Link from "next/link";
import { TriangleAlert } from "lucide-react";

import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";

/**
 * Tells an organizer that the payment gateway is down and their sales have been
 * switched to manual bank transfer.
 *
 * Worth interrupting them for: without a payout account on file there is
 * nowhere for a buyer to transfer, so the event silently cannot sell at all.
 */
export function ManualModeBanner() {
  const { org } = useActiveOrg();

  if (!org || org.payment_gateway_enabled) return null;

  const missingBank = !org.has_bank_account;

  return (
    <Card
      className="mb-5 flex flex-wrap items-start gap-3 p-4"
      style={{
        borderColor: `color-mix(in srgb, var(--warning) 45%, transparent)`,
        background: `color-mix(in srgb, var(--warning) 8%, transparent)`,
      }}
    >
      <TriangleAlert className="mt-0.5 h-5 w-5 shrink-0 text-[var(--warning)]" />
      <div className="min-w-0 flex-1">
        <p className="font-semibold">
          {missingBank
            ? "Pembayaran tidak bisa diterima — rekening belum diisi"
            : "Pembayaran sedang lewat transfer manual"}
        </p>
        <p className="mt-1 text-sm text-muted-foreground">
          Payment gateway sedang bermasalah, jadi pembeli transfer langsung ke rekeningmu dan
          mengunggah bukti untuk kamu verifikasi.{" "}
          {missingBank
            ? "Isi rekening penarikanmu sekarang — tanpa itu tidak ada tujuan transfer dan eventmu tidak bisa menerima pembayaran apa pun."
            : "Kami tidak memotong fee dari pembayaran ini. Periksa antrean verifikasi di tiap event."}
        </p>
      </div>
      {missingBank && (
        <Button asChild size="sm">
          <Link href="/organizer/wallet">Isi rekening</Link>
        </Button>
      )}
    </Card>
  );
}
