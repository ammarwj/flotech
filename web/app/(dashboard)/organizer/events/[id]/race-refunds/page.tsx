"use client";

import { useState } from "react";
import { useParams } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { Check, Inbox, Undo2, X } from "lucide-react";

import {
  approveRaceRefund,
  getRaceRefunds,
  rejectRaceRefund,
} from "@/lib/api/race";
import { getEvent } from "@/lib/api/events";
import { parseApiError } from "@/lib/api/errors";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { rupiah } from "@/lib/labels";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import {
  AlertDialog,
  AlertDialogBody,
  AlertDialogContent,
  AlertDialogFooter,
  AlertDialogHeader,
} from "@/components/ui/alert-dialog";
import { dialogConsequences } from "@/components/ui/dialog";
import { PageHeader } from "@/components/shared/page-header";
import { RaceRouteGuard } from "@/components/event/race-route-guard";
import type { RaceEntry } from "@/types/api";

/**
 * Runners asking for their entry fee back, and what was decided before.
 *
 * Its own page rather than a tab on the runner list, mirroring the backend
 * split: that list is the start list, read constantly and acted on rarely,
 * while this is a queue of decisions — and the two sit behind different
 * concerns even though both are `org.admin`.
 *
 * **Decided requests ride along in the same payload**, not behind a filter: the
 * queue is usually empty, and an empty screen that cannot show what was ruled
 * on last week reads as a feature that lost its data.
 */
export default function RaceRefundsPage() {
  const params = useParams<{ id: string }>();
  const eventId = params.id;
  const qc = useQueryClient();
  const { orgId } = useActiveOrg();

  const [confirming, setConfirming] = useState<RaceEntry | null>(null);
  const [rejecting, setRejecting] = useState<string | null>(null);
  const [reason, setReason] = useState("");

  const eventQuery = useQuery({
    queryKey: ["event", orgId, eventId],
    queryFn: () => getEvent(orgId!, eventId),
    enabled: !!orgId,
  });

  const query = useQuery({
    queryKey: ["race-refunds", orgId, eventId],
    queryFn: () => getRaceRefunds(orgId!, eventId),
    enabled: !!orgId,
  });

  /**
   * Both actions invalidate the wallet as well as this queue, and the wallet
   * one is load-bearing: approving reverses a ledger credit, so a balance left
   * on screen from before the click would overstate what the organizer can
   * withdraw — by exactly the amount they just gave back.
   */
  const done = (message: string) => {
    qc.invalidateQueries({ queryKey: ["race-refunds", orgId, eventId] });
    qc.invalidateQueries({ queryKey: ["race-entries", orgId, eventId] });
    qc.invalidateQueries({ queryKey: ["wallet", orgId] });
    qc.invalidateQueries({ queryKey: ["wallet-transactions", orgId] });
    setConfirming(null);
    setRejecting(null);
    setReason("");
    toast.success(message);
  };

  const approve = useMutation({
    mutationFn: (entry: RaceEntry) => approveRaceRefund(orgId!, eventId, entry.id),
    onSuccess: () =>
      done("Refund disetujui. Transfer balik ke peserta masih dilakukan manual."),
    onError: (err) => toast.error(parseApiError(err, "Gagal menyetujui refund.").message),
  });

  const reject = useMutation({
    mutationFn: ({ id, text }: { id: string; text: string }) =>
      rejectRaceRefund(orgId!, eventId, id, text),
    onSuccess: () => done("Pengajuan refund ditolak."),
    onError: (err) => toast.error(parseApiError(err, "Gagal menolak pengajuan.").message),
  });

  const busy = approve.isPending || reject.isPending;
  const pending = query.data?.pending ?? [];
  const decided = query.data?.decided ?? [];

  return (
    <div className="space-y-6">
      <PageHeader
        title="Pengajuan Refund"
        description="Menyetujui melepas tempatnya dan memotong saldo dompetmu."
        backHref={`/organizer/events/${eventId}/race`}
        backLabel="Peserta Lomba"
      />

      <RaceRouteGuard event={eventQuery.data} eventId={eventId} requires="race">
        {query.isPending && (
          <div className="grid gap-3">
            {[0, 1].map((i) => (
              <Skeleton key={i} className="h-28 rounded-xl" />
            ))}
          </div>
        )}

        {query.isError && (
          <p className="text-sm text-[var(--danger)]">Gagal memuat pengajuan refund.</p>
        )}

        {query.data && pending.length === 0 && decided.length === 0 && (
          <Card className="flex flex-col items-center gap-2 p-10 text-center">
            <Inbox className="h-8 w-8 text-muted-foreground" />
            <p className="font-semibold">Belum ada pengajuan refund</p>
            <p className="text-sm text-muted-foreground">
              Pengajuan dari peserta akan muncul di sini untuk kamu putuskan.
            </p>
          </Card>
        )}

        {pending.length > 0 && (
          <section>
            <h2
              className="mb-3 text-sm font-bold"
              style={{ fontFamily: "var(--font-display)" }}
            >
              Menunggu keputusan ({pending.length})
            </h2>
            <div className="grid gap-3">
              {pending.map((entry) => (
                <Card key={entry.id} className="p-4">
                  <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                      <p className="font-semibold">{entry.full_name}</p>
                      {/* The period beside the category, because it is what
                          makes the amount checkable against the price list —
                          the same reason it is on the receipt. */}
                      <p className="text-sm text-muted-foreground">
                        {entry.category?.name ?? "-"} · {entry.period_name}
                      </p>
                      <p className="mt-1 text-sm font-bold">{rupiah(entry.price)}</p>
                      {entry.refund_requested_at && (
                        <p className="mt-0.5 text-xs text-muted-foreground">
                          Diajukan {fmt(entry.refund_requested_at)}
                        </p>
                      )}
                    </div>
                    <div className="flex flex-wrap gap-2">
                      <Button
                        size="sm"
                        disabled={busy}
                        onClick={() => setConfirming(entry)}
                      >
                        <Check className="h-4 w-4" />
                        Setujui
                      </Button>
                      <Button
                        size="sm"
                        variant="outline"
                        disabled={busy}
                        onClick={() =>
                          setRejecting(rejecting === entry.id ? null : entry.id)
                        }
                      >
                        <X className="h-4 w-4" />
                        Tolak
                      </Button>
                    </div>
                  </div>

                  {/* The runner's own sentence, which is the thing being ruled
                      on — set apart rather than kept as another muted line,
                      because every other line on this card is metadata and this
                      one is the request. */}
                  <p className="mt-3 rounded-md border-l-[3px] border-l-[var(--brand-600)] bg-[var(--bg-soft)] px-3 py-2 text-sm">
                    {entry.refund_reason || "Tanpa alasan."}
                  </p>

                  {rejecting === entry.id && (
                    <div className="mt-3 flex flex-wrap items-center gap-2 border-t border-border pt-3">
                      <Input
                        autoFocus
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                        placeholder="Alasan penolakan (dilihat peserta)"
                        className="min-w-[240px] flex-1"
                      />
                      <Button
                        size="sm"
                        variant="outline"
                        disabled={busy || reason.trim().length === 0}
                        onClick={() =>
                          reject.mutate({ id: entry.id, text: reason.trim() })
                        }
                      >
                        Kirim penolakan
                      </Button>
                    </div>
                  )}
                </Card>
              ))}
            </div>
          </section>
        )}

        {decided.length > 0 && (
          <section>
            <h2
              className="mb-3 text-sm font-bold"
              style={{ fontFamily: "var(--font-display)" }}
            >
              Sudah diputus
            </h2>
            <div className="grid gap-2">
              {decided.map((entry) => (
                <Card
                  key={entry.id}
                  className="flex flex-wrap items-start justify-between gap-3 p-3.5"
                >
                  <div className="min-w-0">
                    <p className="text-sm font-semibold">{entry.full_name}</p>
                    <p className="text-xs text-muted-foreground">
                      {entry.category?.name ?? "-"} · {rupiah(entry.price)}
                      {entry.refund_decided_at && ` · ${fmt(entry.refund_decided_at)}`}
                    </p>
                    {/* Only on a refusal: an approval's explanation is the
                        money having moved, and `refund_rejected_reason` is null
                        there by construction. */}
                    {entry.refund_rejected_reason && (
                      <p className="mt-1 text-xs text-muted-foreground">
                        Ditolak: {entry.refund_rejected_reason}
                      </p>
                    )}
                  </div>
                  <Badge variant={entry.status === "refunded" ? "success" : "danger"}>
                    {entry.status === "refunded" ? "Disetujui" : "Ditolak"}
                  </Badge>
                </Card>
              ))}
            </div>
          </section>
        )}
      </RaceRouteGuard>

      {/* Confirmed before it runs, because it is three irreversible things at
          once — and the one organizers do not expect is the third. Spelled out
          here rather than only in the toast afterwards: by then the credit is
          already reversed. */}
      <AlertDialog
        open={!!confirming}
        onOpenChange={(open) => !open && setConfirming(null)}
      >
        <AlertDialogContent>
          <AlertDialogHeader
            icon={Undo2}
            tone="danger"
            title="Setujui refund?"
            description={
              confirming
                ? `${confirming.full_name} · ${rupiah(confirming.price)}`
                : undefined
            }
          />
          <AlertDialogBody>
            <ul className={dialogConsequences.danger}>
              <li>Pendaftaran dibatalkan dan tempatnya kembali ke kuota.</li>
              <li>Saldo dompetmu dikurangi sebesar biaya pendaftaran ini.</li>
              <li>
                Uangnya <strong>tidak otomatis</strong> kembali ke peserta —
                transfer baliknya kamu lakukan sendiri.
              </li>
            </ul>
          </AlertDialogBody>
          <AlertDialogFooter>
            <Button variant="outline" onClick={() => setConfirming(null)}>
              Batal
            </Button>
            <Button
              variant="destructive"
              disabled={busy}
              onClick={() => confirming && approve.mutate(confirming)}
            >
              {approve.isPending ? "Memproses…" : "Setujui refund"}
            </Button>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}

function fmt(value: string): string {
  return new Date(value).toLocaleString("id-ID", {
    day: "numeric",
    month: "short",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}
