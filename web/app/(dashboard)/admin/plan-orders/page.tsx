"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { CheckCircle2, Inbox, ReceiptText, Wallet } from "lucide-react";

import {
  approvePlanOrder,
  getIdlePlanCredits,
  getPendingPlanOrders,
  getVerifiedPlanOrders,
  rejectPlanOrder,
} from "@/lib/api/admin-wallet";
import { parseApiError } from "@/lib/api/errors";
import { rupiah } from "@/lib/labels";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { PillTabs } from "@/components/event/pill-tabs";
import { ReassignPlanDialog } from "@/components/subscription/reassign-plan-dialog";
import { PaymentProofDialog } from "@/components/payment/payment-proof-dialog";
import type { EventPlanOrder } from "@/types/api";

/** Same shape as /admin/withdrawals and /admin/payments print. */
const fmtDateTime = (iso: string) =>
  new Date(iso).toLocaleString("id-ID", {
    dateStyle: "medium",
    timeStyle: "short",
  });

/**
 * The super admin's manual-payment queue, twin of an event's own at
 * /organizer/events/{id}/payments — but this money lands in flo-event's
 * account, so nobody below super_admin may rule on it.
 *
 * Shown unconditionally, not only while the gateway is off: a bill that already
 * has a receipt attached never expires on its own, so hiding this page the
 * moment Midtrans recovers would strand an organizer who has already paid.
 */
export default function AdminEventPlanOrdersPage() {
  const qc = useQueryClient();
  /**
   * The receipt on screen, and whether there is anything left to decide about
   * it. A settled bill opens the same dialog without the two buttons — see
   * PaymentProofDialog. One piece of state rather than two so the dialog can
   * never be handed a queue row with the verdict hidden, or a settled one with
   * an Approve button that would rule on it twice.
   */
  const [reviewing, setReviewing] = useState<{
    order: EventPlanOrder;
    settled: boolean;
  } | null>(null);
  const [tab, setTab] = useState("queue");

  const query = useQuery({
    queryKey: ["admin-plan-orders"],
    queryFn: getPendingPlanOrders,
  });

  /**
   * Receipts already accepted. A tab beside the queue rather than a page of its
   * own: the question it answers — "did we already accept that transfer?" — is
   * asked while looking at a receipt that resembles one, and the answer is
   * useless a click away.
   */
  const historyQuery = useQuery({
    queryKey: ["admin-plan-order-history"],
    queryFn: getVerifiedPlanOrders,
  });

  const done = (message: string) => {
    qc.invalidateQueries({ queryKey: ["admin-plan-orders"] });
    // A ruling moves the row between these two lists, so the log is as stale as
    // the queue is. Rejections leave it out, but invalidating both is cheaper
    // than being right about which verdict just happened.
    qc.invalidateQueries({ queryKey: ["admin-plan-order-history"] });
    setReviewing(null);
    toast.success(message);
  };

  const approve = useMutation({
    mutationFn: (id: string) => approvePlanOrder(id),
    onSuccess: () => done("Pembayaran diterima. Paket sudah aktif."),
    onError: (err) =>
      toast.error(parseApiError(err, "Gagal menerima pembayaran.").message),
  });

  const reject = useMutation({
    mutationFn: ({ id, text }: { id: string; text: string }) =>
      rejectPlanOrder(id, text),
    onSuccess: () => done("Bukti ditolak. Organizer dapat mengunggah ulang."),
    onError: (err) =>
      toast.error(parseApiError(err, "Gagal menolak bukti.").message),
  });

  /**
   * Paid plans nobody has spent. Deliberately below the verification queue and
   * not mixed into it: that queue is work waiting on a decision, this is a
   * standing balance of entitlements already paid for. Nothing here is overdue —
   * a credit has no expiry — so there is no action button, only visibility and
   * whether `plan-orders:remind-idle` has nudged them yet.
   */
  const [reassigning, setReassigning] = useState<EventPlanOrder | null>(null);

  const idleQuery = useQuery({
    queryKey: ["admin-idle-plan-credits"],
    queryFn: getIdlePlanCredits,
  });

  const rows = query.data ?? [];
  const idle = idleQuery.data ?? [];
  const history = historyQuery.data ?? [];
  const busy = approve.isPending || reject.isPending;


  return (
    <div>
      <PageHeader
        title="Verifikasi Pembelian Paket"
        description="Pembayaran paket lewat transfer manual."
      />

      {/* Three lists that share a page but not a job: work waiting on a
          decision, decisions already made, and money owed in entitlements
          nobody has claimed. Tabs rather than stacked sections — scrolling past
          two lists to reach the third made the queue, which is the only one
          with anything to do, look like a footnote to its own page. */}
      <PillTabs
        items={[
          { key: "queue", label: `Menunggu verifikasi${rows.length ? ` (${rows.length})` : ""}`, icon: Inbox },
          { key: "history", label: "Riwayat", icon: CheckCircle2 },
          { key: "idle", label: `Kredit menganggur${idle.length ? ` (${idle.length})` : ""}`, icon: Wallet },
        ]}
        activeKey={tab}
        onSelect={setTab}
      />

      <div className="mt-5">
        {tab === "queue" && (
          <>
            {query.isPending && (
              <div className="grid gap-3">
                {[0, 1].map((i) => (
                  <Skeleton key={i} className="h-28 rounded-xl" />
                ))}
              </div>
            )}

            {query.isError && (
              <p className="text-sm text-[var(--danger)]">
                Gagal memuat antrean (butuh akses Super Admin &amp; API berjalan).
              </p>
            )}

            {query.data && rows.length === 0 && (
              <Card className="flex flex-col items-center gap-2 p-10 text-center">
                <Inbox className="h-8 w-8 text-muted-foreground" />
                <p className="font-semibold">Tidak ada yang menunggu verifikasi</p>
                <p className="text-sm text-muted-foreground">
                  Bukti transfer pembayaran paket akan muncul di sini.
                </p>
              </Card>
            )}

            <div className="grid gap-3">
              {rows.map((sub) => (
                <Card key={sub.id} className="p-4">
                  <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                      <p className="font-semibold">
                        {sub.organization?.name ?? "Organisasi dihapus"}
                      </p>
                      <p className="text-sm text-muted-foreground">
                        {sub.plan?.name ?? "Paket dihapus"} &middot;{" "}
                        {sub.event?.name ?? "Belum dipakai"} &middot;{" "}
                        {sub.invoice_number ?? "—"}
                      </p>
                      <p className="mt-1 text-sm font-bold">{rupiah(sub.amount)}</p>
                      {sub.payment_proof_uploaded_at && (
                        <p className="mt-0.5 text-xs text-muted-foreground">
                          Diunggah {fmtDateTime(sub.payment_proof_uploaded_at)}
                        </p>
                      )}
                    </div>
                    {/* One button, because there is one decision — and it can
                        only be made after looking at the receipt, which is
                        inside. */}
                    <Button
                      size="sm"
                      disabled={busy}
                      onClick={() => setReviewing({ order: sub, settled: false })}
                    >
                      <ReceiptText className="h-4 w-4" />
                      Lihat bukti &amp; verifikasi
                    </Button>
                  </div>
                </Card>
              ))}
            </div>
          </>
        )}

        {tab === "history" && (
          <>
            <p className="mb-3 text-sm text-muted-foreground">
              Bukti transfer yang sudah di-acc. Yang ditolak tidak muncul di sini —
              organizer masih bisa mengunggah ulang, jadi perkaranya belum selesai.
            </p>

            {historyQuery.isPending && <Skeleton className="h-24 rounded-xl" />}

            {historyQuery.data && history.length === 0 && (
              <p className="text-sm text-muted-foreground">
                Belum ada pembayaran paket yang diverifikasi.
              </p>
            )}

            <div className="grid gap-2">
              {history.map((order) => (
                <Card
                  key={order.id}
                  className="flex flex-wrap items-center justify-between gap-3 p-4"
                >
                  <div className="min-w-0">
                    <p className="font-semibold">
                      {order.organization?.name ?? "Organisasi dihapus"}
                    </p>
                    <p className="text-sm text-muted-foreground">
                      {order.plan?.name ?? "Paket dihapus"} &middot;{" "}
                      {rupiah(order.amount)} &middot;{" "}
                      {order.receipt_number ?? order.invoice_number ?? "—"}
                    </p>
                    {/* Which event spent the credit — or that nobody has yet.
                        The same pair the idle list is built from, per row. */}
                    <p className="mt-0.5 text-xs text-muted-foreground">
                      {order.event?.name ?? "Kredit belum dipakai"}
                    </p>
                  </div>
                  <div className="flex flex-wrap items-center gap-4">
                    <div className="text-right text-sm">
                      <p className="flex items-center justify-end gap-1.5 font-medium text-[var(--success)]">
                        <CheckCircle2 className="h-4 w-4" />
                        Diterima
                      </p>
                      <p className="mt-0.5 text-xs text-muted-foreground">
                        {order.verified_at ? fmtDateTime(order.verified_at) : "—"}
                        {order.verified_by ? ` · ${order.verified_by}` : ""}
                      </p>
                    </div>
                    {/* The same dialog the queue opens, minus the verdict. A
                        receipt is looked up long after it was ruled on — "was
                        this the transfer we accepted?" — and the answer is the
                        image, not the row. */}
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => setReviewing({ order, settled: true })}
                    >
                      <ReceiptText className="h-4 w-4" />
                      Lihat bukti
                    </Button>
                  </div>
                </Card>
              ))}
            </div>
          </>
        )}

        {tab === "idle" && (
          <>
            <p className="mb-3 text-sm text-muted-foreground">
              Paket lunas yang belum dipakai untuk event apa pun.
            </p>

            {idleQuery.isPending && <Skeleton className="h-24 rounded-xl" />}

            {idleQuery.data && idle.length === 0 && (
              <p className="text-sm text-muted-foreground">
                Tidak ada kredit yang menganggur. Semua paket lunas sudah dipakai.
              </p>
            )}

            <div className="grid gap-2">
              {idle.map((credit) => (
                <Card
                  key={credit.id}
                  className="flex flex-wrap items-center justify-between gap-3 p-4"
                >
                  <div className="min-w-0">
                    <p className="font-semibold">
                      {credit.organization?.name ?? "Organisasi dihapus"}
                    </p>
                    <p className="text-sm text-muted-foreground">
                      {credit.plan?.name ?? "Paket dihapus"} &middot;{" "}
                      {rupiah(credit.amount)} &middot;{" "}
                      {credit.invoice_number ?? "—"}
                    </p>
                  </div>
                  <div className="flex flex-wrap items-center gap-4">
                    <div className="text-right text-sm">
                      <p className="text-muted-foreground">
                        Dibayar {credit.paid_at ? fmtDateTime(credit.paid_at) : "—"}
                      </p>
                      <p className="mt-0.5 text-xs text-muted-foreground">
                        {credit.idle_reminded_at
                          ? `Diingatkan ${fmtDateTime(credit.idle_reminded_at)}`
                          : "Belum pernah diingatkan"}
                      </p>
                    </div>
                    {/* The escape hatch for a plan bought against the wrong
                        event. It lived only as an API call until now. */}
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => setReassigning(credit)}
                    >
                      Pakai untuk event lain
                    </Button>
                  </div>
                </Card>
              ))}
            </div>
          </>
        )}
      </div>

      {reassigning && (
        <ReassignPlanDialog
          credit={reassigning}
          open
          onOpenChange={(next) => !next && setReassigning(null)}
        />
      )}

      {reviewing && (
        <PaymentProofDialog
          open
          onOpenChange={(next) => !next && setReviewing(null)}
          title={reviewing.settled ? "Bukti transfer (sudah diterima)" : "Bukti transfer paket"}
          description={`${reviewing.order.organization?.name ?? "Organisasi dihapus"} · ${
            reviewing.order.invoice_number ?? "tanpa nomor invoice"
          }`}
          proofUrl={reviewing.order.payment_proof_url}
          uploadedAt={reviewing.order.payment_proof_uploaded_at}
          details={[
            {
              label: "Organisasi",
              value: reviewing.order.organization?.name ?? "Organisasi dihapus",
            },
            {
              label: "Paket",
              value: `${reviewing.order.plan?.name ?? "Paket dihapus"} · ${
                reviewing.order.event?.name ?? "Belum dipakai"
              }`,
            },
            { label: "Invoice", value: reviewing.order.invoice_number ?? "—" },
            {
              label: "Jumlah",
              value: (
                <span className="font-bold">{rupiah(reviewing.order.amount)}</span>
              ),
            },
            // Only on a settled bill, and it is the whole reason to reopen one.
            ...(reviewing.settled
              ? [
                  {
                    label: "Diterima",
                    value: `${
                      reviewing.order.verified_at
                        ? fmtDateTime(reviewing.order.verified_at)
                        : "—"
                    }${reviewing.order.verified_by ? ` · ${reviewing.order.verified_by}` : ""}`,
                  },
                ]
              : []),
          ]}
          consequence={
            reviewing.settled
              ? undefined
              : "Menerima berarti mengaktifkan paket ini seketika. Cocokkan dulu jumlah dan waktu transfer dengan mutasi rekening flo-event — tidak ada uang yang masuk otomatis."
          }
          rejectPlaceholder="Alasan penolakan (dilihat organizer)"
          busy={busy}
          onApprove={
            reviewing.settled ? undefined : () => approve.mutate(reviewing.order.id)
          }
          onReject={
            reviewing.settled
              ? undefined
              : (text) => reject.mutate({ id: reviewing.order.id, text })
          }
        />
      )}
    </div>
  );
}
