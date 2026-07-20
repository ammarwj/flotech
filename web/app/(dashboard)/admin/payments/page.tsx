"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { AlertTriangle, ReceiptText, RotateCcw } from "lucide-react";

import { usePrompt } from "@/components/shared/confirm-provider";

import { getAdminPayments, refundPayment } from "@/lib/api/admin-wallet";
import { parseApiError } from "@/lib/api/errors";
import { rupiah } from "@/lib/labels";
import { cn } from "@/lib/utils";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { EmptyState } from "@/components/shared/empty-state";
import type { AdminPayment } from "@/types/api";

const TABS = [
  { value: "paid", label: "Lunas" },
  { value: "refunded", label: "Direfund" },
  { value: "all", label: "Semua" },
];

const dateTime = (iso: string | null) =>
  iso ? new Date(iso).toLocaleString("id-ID", { dateStyle: "medium", timeStyle: "short" }) : "—";

export default function AdminPaymentsPage() {
  const prompt = usePrompt();
  const qc = useQueryClient();
  const [tab, setTab] = useState("paid");

  const query = useQuery({
    queryKey: ["admin-payments", tab],
    queryFn: () => getAdminPayments(tab),
  });

  const refundMutation = useMutation({
    mutationFn: ({ row, reason }: { row: AdminPayment; reason: string }) =>
      refundPayment(row.kind, row.id, reason),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["admin-payments"] });
      toast.success("Pembayaran direfund", {
        description: "Saldo organizer sudah dikoreksi. Refund juga di dashboard Midtrans.",
      });
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal melakukan refund.").message),
  });

  const refund = async (row: AdminPayment) => {
    const reason = await prompt({
      title: `Refund ${rupiah(row.amount)}?`,
      description: `Pesanan dari ${row.payer ?? "pembeli"} dibatalkan dan saldo organizer dikoreksi.`,
      consequences:
        "Uang ke pembeli TIDAK otomatis dikembalikan — lakukan refund manual di dashboard Midtrans.",
      label: "Alasan refund",
      placeholder: "mis. pembeli batal hadir",
      multiline: true,
      confirmLabel: "Refund",
      tone: "danger",
      icon: RotateCcw,
    });
    if (!reason) return;
    refundMutation.mutate({ row, reason });
  };

  const items = query.data ?? [];

  return (
    <>
      <PageHeader
        title="Pembayaran & Refund"
        description="Semua uang masuk ke akun Midtrans platform. Refund di sini membatalkan pesanan dan mengoreksi saldo organizer."
      />

      <Card className="mb-6 flex items-start gap-3 border-[var(--warning)]/40 bg-[var(--warning)]/5 p-4">
        <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0 text-[var(--warning)]" />
        <p className="text-sm text-muted-foreground">
          Refund di sini <strong className="text-foreground">tidak</strong> mengembalikan uang ke
          pembeli. Kamu tetap harus memproses refund-nya di dashboard Midtrans.
        </p>
      </Card>

      <div className="mb-6 flex flex-wrap gap-2">
        {TABS.map(({ value, label }) => (
          <button
            key={value}
            onClick={() => setTab(value)}
            className={cn(
              "rounded-lg px-3 py-1.5 text-sm font-medium transition-colors",
              tab === value
                ? "bg-[var(--tint)] text-[var(--brand-600)]"
                : "text-muted-foreground hover:bg-accent hover:text-foreground"
            )}
          >
            {label}
          </button>
        ))}
      </div>

      {query.isLoading ? (
        <div className="grid gap-3">
          {[0, 1, 2].map((i) => (
            <Skeleton key={i} className="h-[88px] rounded-xl" />
          ))}
        </div>
      ) : items.length === 0 ? (
        <EmptyState
          icon={ReceiptText}
          title="Tidak ada pembayaran"
          description="Penjualan tiket dan biaya pendaftaran akan muncul di sini."
        />
      ) : (
        <div className="grid gap-3">
          {items.map((row) => (
            <Card
              key={`${row.kind}-${row.id}`}
              className="flex flex-wrap items-center justify-between gap-4 p-4"
            >
              <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                  <span className="font-semibold tabular-nums">{rupiah(row.amount)}</span>
                  <Badge variant={row.status === "paid" ? "success" : "neutral"}>
                    {row.status === "paid" ? "Lunas" : "Direfund"}
                  </Badge>
                  <Badge variant="outline">
                    {row.kind === "ticket_order" ? "Tiket" : "Pendaftaran"}
                  </Badge>
                </div>
                <p className="mt-1 truncate text-sm text-muted-foreground">
                  {row.payer ?? "—"} &middot; {row.event_name ?? "—"} &middot;{" "}
                  {row.organization_name ?? "—"}
                </p>
                <p className="mt-0.5 text-xs text-muted-foreground">
                  {row.reference ?? "—"} &middot; {dateTime(row.paid_at)} &middot; biaya platform{" "}
                  {rupiah(row.platform_fee)}
                </p>
              </div>

              {row.status === "paid" && (
                <Button
                  variant="destructive"
                  size="sm"
                  disabled={refundMutation.isPending}
                  onClick={() => void refund(row)}
                >
                  Refund
                </Button>
              )}
            </Card>
          ))}
        </div>
      )}
    </>
  );
}
