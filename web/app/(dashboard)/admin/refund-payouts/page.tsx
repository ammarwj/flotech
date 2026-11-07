"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { ArrowRightLeft, CircleAlert, Receipt } from "lucide-react";

import {
  completeRefundPayout,
  getAdminRefundPayouts,
} from "@/lib/api/refund-payouts";
import { parseApiError } from "@/lib/api/errors";
import { rupiah } from "@/lib/labels";
import { payoutLabels } from "@/lib/payout";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { EmptyState } from "@/components/shared/empty-state";
import { RefundTransferDialog } from "@/components/shared/refund-transfer-dialog";
import {
  DocumentViewerDialog,
  type ViewerDocument,
} from "@/components/shared/document-viewer-dialog";
import type { RefundPayout } from "@/types/api";

/**
 * Refunds the platform still owes organizers.
 *
 * This screen exists because granting a refund was silent by construction: it
 * reverses the organizer's wallet credit immediately, but the buyer's money is
 * in the platform's own Midtrans account, so until somebody transfers it the
 * refund is only a balance that went down. Nothing notified anybody, and race
 * entries were not even listed on /admin/payments — the debt was invisible even
 * to somebody looking for it.
 *
 * `pending` is oldest-first, matching the server: somebody is waiting on each
 * of these, and newest-first buries whoever has waited longest.
 */

const dateTime = (iso: string | null) =>
  iso ? new Date(iso).toLocaleString("id-ID", { dateStyle: "medium", timeStyle: "short" }) : "—";

const TABS = [
  { value: "pending", label: "Menunggu transfer" },
  { value: "completed", label: "Sudah ditransfer" },
] as const;

export default function AdminRefundPayoutsPage() {
  const qc = useQueryClient();
  const [tab, setTab] = useState<(typeof TABS)[number]["value"]>("pending");
  const [transferring, setTransferring] = useState<RefundPayout | null>(null);
  // Proof opens in place, not a new tab: an admin working this queue is
  // checking a receipt against the row it belongs to, and a tab throws that row
  // away — the scroll position, the tab they were on, and the queue they were
  // halfway through. The same call `DocumentViewerDialog` already makes for
  // registration scans, which is why this reuses it rather than adding a fourth
  // preview dialog. Its own "Buka di tab baru" button is still there for
  // anybody who wants it.
  const [viewing, setViewing] = useState<ViewerDocument | null>(null);

  const query = useQuery({
    queryKey: ["admin-refund-payouts"],
    queryFn: getAdminRefundPayouts,
  });

  const completeMutation = useMutation({
    mutationFn: ({ id, proofUrl, reference }: { id: string; proofUrl: string; reference: string | null }) =>
      completeRefundPayout(id, { proof_url: proofUrl, transfer_reference: reference }),
    onSuccess: () => {
      setTransferring(null);
      qc.invalidateQueries({ queryKey: ["admin-refund-payouts"] });
      toast.success("Transfer ditandai selesai. Penyelenggara sudah diberi tahu.");
    },
    onError: (err: unknown) =>
      toast.error(parseApiError(err, "Gagal menandai transfer refund.").message),
  });

  const rows = tab === "pending" ? query.data?.pending ?? [] : query.data?.completed ?? [];
  const pendingCount = query.data?.pending.length ?? 0;

  return (
    <div className="grid gap-5">
      <PageHeader
        title="Transfer Refund"
        description="Refund yang sudah disetujui, menunggu dana dikirim ke rekening penyelenggara. Penyelenggara yang meneruskannya ke peserta."
      />

      <div className="flex flex-wrap gap-1.5">
        {TABS.map((t) => (
          <button
            key={t.value}
            onClick={() => setTab(t.value)}
            className={cn(
              "rounded-lg px-3 py-1.5 text-sm font-semibold transition-colors",
              tab === t.value
                ? "bg-[var(--brand-600)] text-white"
                : "border border-border text-muted-foreground hover:bg-accent hover:text-foreground"
            )}
          >
            {t.label}
            {t.value === "pending" && pendingCount > 0 && (
              <span className="ml-1.5 tabular-nums">({pendingCount})</span>
            )}
          </button>
        ))}
      </div>

      {query.isLoading ? (
        <div className="grid gap-3">
          <Skeleton className="h-28" />
          <Skeleton className="h-28" />
        </div>
      ) : rows.length === 0 ? (
        <EmptyState
          icon={ArrowRightLeft}
          title={tab === "pending" ? "Tidak ada transfer tertunda" : "Belum ada transfer selesai"}
          description={
            tab === "pending"
              ? "Setiap refund yang disetujui akan muncul di sini sampai dananya dikirim ke penyelenggara."
              : "Transfer yang sudah ditandai selesai akan tercatat di sini."
          }
        />
      ) : (
        <div className="grid gap-3">
          {rows.map((p) => (
            <Card key={p.id} className="grid gap-3 p-4">
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                  <p className="text-sm font-bold">{p.recipient_name}</p>
                  <p className="text-xs text-muted-foreground">
                    {p.source_label}
                    {p.event_name ? ` · ${p.event_name}` : ""}
                  </p>
                  <p className="mt-0.5 text-xs text-muted-foreground">
                    {p.organization_name ?? "—"}
                  </p>
                </div>
                <p className="text-base font-bold tabular-nums">{rupiah(p.amount)}</p>
              </div>

              {p.reason && (
                <p className="rounded-lg border border-border bg-[var(--bg-alt)] p-2.5 text-sm text-muted-foreground">
                  {p.reason}
                </p>
              )}

              <dl className="grid gap-1 text-xs sm:grid-cols-2">
                <div className="flex gap-1.5">
                  <dt className="text-muted-foreground">
                    {payoutLabels(p.account_type).institution}:
                  </dt>
                  <dd className="font-medium">
                    {p.bank_name ?? <span className="text-destructive">belum diisi</span>}
                  </dd>
                </div>
                <div className="flex gap-1.5">
                  <dt className="text-muted-foreground">
                    {payoutLabels(p.account_type).number}:
                  </dt>
                  <dd className="font-mono font-medium">{p.account_number ?? "—"}</dd>
                </div>
                <div className="flex gap-1.5">
                  <dt className="text-muted-foreground">
                    {payoutLabels(p.account_type).holder}:
                  </dt>
                  <dd className="font-medium">{p.account_holder ?? "—"}</dd>
                </div>
                <div className="flex gap-1.5">
                  <dt className="text-muted-foreground">Refund disetujui:</dt>
                  <dd className="font-medium">{dateTime(p.created_at)}</dd>
                </div>
              </dl>

              {!p.account_number && p.awaiting_transfer && (
                <p className="flex items-start gap-1.5 text-xs text-destructive">
                  <CircleAlert className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                  Penyelenggara belum punya rekening utama. Hubungi mereka sebelum mentransfer.
                </p>
              )}

              {p.awaiting_transfer ? (
                <div className="flex justify-end">
                  <Button size="sm" onClick={() => setTransferring(p)}>
                    Tandai sudah ditransfer
                  </Button>
                </div>
              ) : (
                <div className="grid gap-1.5 border-t border-border pt-3 text-xs">
                  <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <span className="text-muted-foreground">
                      Ditransfer {dateTime(p.completed_at)}
                    </span>
                    {p.transfer_reference && (
                      <span className="font-mono">{p.transfer_reference}</span>
                    )}
                    {p.proof_url && (
                      <button
                        type="button"
                        onClick={() =>
                          setViewing({
                            title: `Bukti transfer — ${p.recipient_name}`,
                            url: p.proof_url!,
                          })
                        }
                        className="inline-flex items-center gap-1 font-medium text-[var(--brand-600)] underline"
                      >
                        <Receipt className="h-3 w-3" />
                        Bukti transfer
                      </button>
                    )}
                  </div>
                  {/* Hop two, read-only here: this is the organizer's own step,
                      and showing it is what tells an admin whether a chasing
                      participant has actually been paid. */}
                  <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <span className="text-muted-foreground">
                      {p.forwarded_at
                        ? `Diteruskan ke peserta ${dateTime(p.forwarded_at)}`
                        : "Belum ditandai diteruskan ke peserta oleh penyelenggara"}
                    </span>
                    {p.forwarded_proof_url && (
                      <button
                        type="button"
                        onClick={() =>
                          setViewing({
                            title: `Bukti penyelenggara ke ${p.recipient_name}`,
                            url: p.forwarded_proof_url!,
                          })
                        }
                        className="inline-flex items-center gap-1 font-medium text-[var(--brand-600)] underline"
                      >
                        <Receipt className="h-3 w-3" />
                        Bukti penyelenggara
                      </button>
                    )}
                  </div>
                </div>
              )}
            </Card>
          ))}
        </div>
      )}

      <RefundTransferDialog
        key={transferring?.id ?? "none"}
        payout={transferring}
        hop="platform"
        pending={completeMutation.isPending}
        onClose={() => setTransferring(null)}
        onSubmit={({ proofUrl, reference }) =>
          transferring &&
          completeMutation.mutate({ id: transferring.id, proofUrl, reference })
        }
      />

      <DocumentViewerDialog document={viewing} onClose={() => setViewing(null)} />
    </div>
  );
}
