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
      className="mb-4 flex flex-col gap-3 p-3 sm:mb-5 sm:flex-row sm:items-center sm:p-4"
      style={{
        borderColor: `color-mix(in srgb, var(--warning) 45%, transparent)`,
        background: `color-mix(in srgb, var(--warning) 8%, transparent)`,
      }}
    >
      <div className="flex min-w-0 flex-1 items-start gap-2.5 sm:gap-3">
        <TriangleAlert className="mt-0.5 h-4 w-4 shrink-0 text-[var(--warning)] sm:h-5 sm:w-5" />
        <div className="min-w-0 flex-1">
          <p className="text-sm font-semibold sm:text-base">
            {missingBank
              ? "Rekening belum diisi — pembayaran tidak bisa diterima"
              : "Pembayaran sedang lewat transfer manual"}
          </p>
          {/* Dua versi teks: paragraf panjang ini bikin kartunya setinggi
              setengah viewport di mobile dan mendorong tombol jauh ke bawah. */}
          <p className="mt-0.5 text-sm text-muted-foreground sm:hidden">
            {missingBank
              ? "Payment gateway bermasalah. Isi rekening penarikan supaya eventmu bisa menerima pembayaran."
              : "Payment gateway bermasalah. Pembeli transfer ke rekeningmu — cek antrean verifikasi tiap event."}
          </p>
          <p className="mt-1 hidden text-sm text-muted-foreground sm:block">
            Payment gateway sedang bermasalah, jadi pembeli transfer langsung ke rekeningmu dan
            mengunggah bukti untuk kamu verifikasi.{" "}
            {missingBank
              ? "Isi rekening penarikanmu sekarang — tanpa itu tidak ada tujuan transfer dan eventmu tidak bisa menerima pembayaran apa pun."
              : "Kami tidak memotong fee dari pembayaran ini. Periksa antrean verifikasi di tiap event."}
          </p>
        </div>
      </div>
      {missingBank && (
        <Button asChild size="sm" className="w-full shrink-0 sm:w-auto">
          <Link href="/organizer/wallet">Isi rekening</Link>
        </Button>
      )}
    </Card>
  );
}
