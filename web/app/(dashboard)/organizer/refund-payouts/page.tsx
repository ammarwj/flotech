"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { ArrowRightLeft, Receipt } from "lucide-react";

import { forwardRefundPayout, getRefundPayouts } from "@/lib/api/refund-payouts";
import { parseApiError } from "@/lib/api/errors";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { rupiah } from "@/lib/labels";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { EmptyState } from "@/components/shared/empty-state";
import { RedirectIfAdmin } from "@/components/auth/redirect-if-admin";
import { RefundTransferDialog } from "@/components/shared/refund-transfer-dialog";
import {
  DocumentViewerDialog,
  type ViewerDocument,
} from "@/components/shared/document-viewer-dialog";
import type { RefundPayout } from "@/types/api";

/**
 * Refunds coming to this organizer, and the ones they owe onward.
 *
 * Hop two of the chain. When a refund is granted the organizer's wallet credit
 * is reversed immediately, then the platform transfers that money to their
 * registered account — and forwarding it to the participant is theirs to do,
 * because the platform has never held the participant's bank details.
 *
 * Rows still pending at the platform are shown deliberately: that is the
 * organizer's answer to a participant asking where their money is.
 */

const dateTime = (iso: string | null) =>
  iso ? new Date(iso).toLocaleString("id-ID", { dateStyle: "medium", timeStyle: "short" }) : "—";

function StatusLine({ payout }: { payout: RefundPayout }) {
  if (payout.awaiting_transfer) {
    return (
      <p className="text-xs text-muted-foreground">
        Menunggu dana dari flo-event. Saldo dompet Anda sudah dikurangi sebesar ini saat refund
        disetujui.
      </p>
    );
  }

  if (payout.awaiting_forwarding) {
    return (
      <p className="text-xs font-medium text-[var(--warning-600,#9a6410)]">
        Dana sudah kami transfer ke rekening Anda. Teruskan ke peserta, lalu tandai di sini.
      </p>
    );
  }

  return (
    <p className="text-xs text-muted-foreground">
      Sudah diteruskan ke peserta {dateTime(payout.forwarded_at)}.
    </p>
  );
}

export default function OrganizerRefundPayoutsPage() {
  const { org } = useActiveOrg();
  const qc = useQueryClient();
  const [forwarding, setForwarding] = useState<RefundPayout | null>(null);
  // In place rather than a new tab — the organizer is checking a receipt
  // against the refund it belongs to, and a tab throws that row away. Reuses
  // the viewer the registration scans already use.
  const [viewing, setViewing] = useState<ViewerDocument | null>(null);

  const query = useQuery({
    queryKey: ["refund-payouts", org?.id],
    queryFn: () => getRefundPayouts(org!.id),
    enabled: !!org?.id,
  });

  const forwardMutation = useMutation({
    mutationFn: ({ id, proofUrl, reference }: { id: string; proofUrl: string; reference: string | null }) =>
      forwardRefundPayout(org!.id, id, {
        forwarded_proof_url: proofUrl,
        forwarded_reference: reference,
      }),
    onSuccess: () => {
      setForwarding(null);
      qc.invalidateQueries({ queryKey: ["refund-payouts"] });
      // The per-event refund queue reads the same payout through
      // `refund_transfer`, so its badge would keep saying "Perlu diteruskan"
      // over a row this click just finished. Keyed by event there, so the
      // prefix invalidates every one the organizer has open.
      qc.invalidateQueries({ queryKey: ["race-refunds"] });
      toast.success("Ditandai sudah diteruskan ke peserta.");
    },
    onError: (err: unknown) =>
      toast.error(parseApiError(err, "Gagal menandai refund diteruskan.").message),
  });

  const rows = query.data ?? [];
  const needsAction = rows.filter((r) => r.awaiting_forwarding).length;

  return (
    <>
      <RedirectIfAdmin />
      <div className="grid gap-5">
        <PageHeader
          title="Transfer Refund"
          description="Refund peserta yang sudah disetujui. flo-event mentransfer dananya ke rekening Anda, lalu Anda meneruskannya ke pesertanya."
        />

        {needsAction > 0 && (
          <Card className="border-[var(--brand-600)] bg-[var(--tint)] p-4">
            <p className="text-sm font-semibold">
              {needsAction} refund menunggu Anda teruskan ke peserta
            </p>
            <p className="mt-0.5 text-xs text-muted-foreground">
              Dananya sudah masuk ke rekening Anda. Pesertanya melihat status ini di halaman
              pendaftarannya sendiri.
            </p>
          </Card>
        )}

        {query.isLoading ? (
          <div className="grid gap-3">
            <Skeleton className="h-28" />
            <Skeleton className="h-28" />
          </div>
        ) : rows.length === 0 ? (
          <EmptyState
            icon={ArrowRightLeft}
            title="Belum ada refund"
            description="Refund peserta yang Anda setujui akan muncul di sini, beserta status transfernya."
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
                  </div>
                  <p className="text-base font-bold tabular-nums">{rupiah(p.amount)}</p>
                </div>

                {p.reason && (
                  <p className="rounded-lg border border-border bg-[var(--bg-alt)] p-2.5 text-sm text-muted-foreground">
                    {p.reason}
                  </p>
                )}

                <StatusLine payout={p} />

                {(p.proof_url || p.forwarded_proof_url) && (
                  <div className="flex flex-wrap items-center gap-x-3 gap-y-1 border-t border-border pt-3 text-xs">
                    {p.proof_url && (
                      <button
                        type="button"
                        onClick={() =>
                          setViewing({
                            title: `Bukti transfer dari flo-event — ${p.recipient_name}`,
                            url: p.proof_url!,
                          })
                        }
                        className="inline-flex items-center gap-1 font-medium text-[var(--brand-600)] underline"
                      >
                        <Receipt className="h-3 w-3" />
                        Bukti dari flo-event
                      </button>
                    )}
                    {p.forwarded_proof_url && (
                      <button
                        type="button"
                        onClick={() =>
                          setViewing({
                            title: `Bukti Anda ke ${p.recipient_name}`,
                            url: p.forwarded_proof_url!,
                          })
                        }
                        className="inline-flex items-center gap-1 font-medium text-[var(--brand-600)] underline"
                      >
                        <Receipt className="h-3 w-3" />
                        Bukti Anda ke peserta
                      </button>
                    )}
                  </div>
                )}

                {/* Shown only when there is actually something to forward — a
                    greyed control beside rows the platform has not paid yet
                    invites a click that can only answer 422, the same call the
                    race list makes for "Batalkan". */}
                {p.awaiting_forwarding && (
                  <div className="flex justify-end">
                    <Button size="sm" onClick={() => setForwarding(p)}>
                      Tandai sudah diteruskan
                    </Button>
                  </div>
                )}
              </Card>
            ))}
          </div>
        )}

        <RefundTransferDialog
          key={forwarding?.id ?? "none"}
          payout={forwarding}
          hop="organizer"
          pending={forwardMutation.isPending}
          onClose={() => setForwarding(null)}
          onSubmit={({ proofUrl, reference }) =>
            forwarding && forwardMutation.mutate({ id: forwarding.id, proofUrl, reference })
          }
        />

        <DocumentViewerDialog document={viewing} onClose={() => setViewing(null)} />
      </div>
    </>
  );
}
