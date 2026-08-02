"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { Inbox, ReceiptText } from "lucide-react";

import {
  approvePlanOrder,
  getIdlePlanCredits,
  getPendingPlanOrders,
  rejectPlanOrder,
} from "@/lib/api/admin-wallet";
import { parseApiError } from "@/lib/api/errors";
import { rupiah } from "@/lib/labels";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { ReassignPlanDialog } from "@/components/subscription/reassign-plan-dialog";
import { PaymentProofDialog } from "@/components/payment/payment-proof-dialog";
import type { EventPlanOrder } from "@/types/api";

/** Same shape as /admin/withdrawals and /admin/payments print. */
const fmtDateTime = (iso: string) =>
  new Date(iso).toLocaleString("id-ID", { dateStyle: "medium", timeStyle: "short" });

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
  // The row under review, not just its id: the dialog needs the whole bill, and
  // holding the object keeps it rendered while the queue refetches around it.
  const [reviewing, setReviewing] = useState<EventPlanOrder | null>(null);

  const query = useQuery({
    queryKey: ["admin-plan-orders"],
    queryFn: getPendingPlanOrders,
  });

  const done = (message: string) => {
    qc.invalidateQueries({ queryKey: ["admin-plan-orders"] });
    setReviewing(null);
    toast.success(message);
  };

  const approve = useMutation({
    mutationFn: (id: string) => approvePlanOrder(id),
    onSuccess: () => done("Pembayaran diterima. Paket sudah aktif."),
    onError: (err) => toast.error(parseApiError(err, "Gagal menerima pembayaran.").message),
  });

  const reject = useMutation({
    mutationFn: ({ id, text }: { id: string; text: string }) => rejectPlanOrder(id, text),
    onSuccess: () => done("Bukti ditolak. Organizer dapat mengunggah ulang."),
    onError: (err) => toast.error(parseApiError(err, "Gagal menolak bukti.").message),
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
  const busy = approve.isPending || reject.isPending;

  return (
    <div>
      <PageHeader
        title="Verifikasi Pembelian Paket"
        description="Pembayaran paket lewat transfer manual. Uangnya masuk ke rekening flo-event — cocokkan dengan mutasi bank sebelum menerima, karena menerima berarti mengaktifkan paket tanpa uang masuk."
      />

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
                <p className="font-semibold">{sub.organization?.name ?? "Organisasi dihapus"}</p>
                <p className="text-sm text-muted-foreground">
                  {sub.plan?.name ?? "Paket dihapus"} &middot;{" "}
                  {sub.event?.name ?? "Belum dipakai"} &middot; {sub.invoice_number ?? "—"}
                </p>
                <p className="mt-1 text-sm font-bold">{rupiah(sub.amount)}</p>
                {sub.payment_proof_uploaded_at && (
                  <p className="mt-0.5 text-xs text-muted-foreground">
                    Diunggah {fmtDateTime(sub.payment_proof_uploaded_at)}
                  </p>
                )}
              </div>
              {/* One button, because there is one decision — and it can only be
                  made after looking at the receipt, which is inside. */}
              <Button size="sm" disabled={busy} onClick={() => setReviewing(sub)}>
                <ReceiptText className="h-4 w-4" />
                Lihat bukti &amp; verifikasi
              </Button>
            </div>
          </Card>
        ))}
      </div>

      <section className="mt-10">
        <h2 className="text-lg font-bold" style={{ fontFamily: "var(--font-display)" }}>
          Kredit menganggur
        </h2>
        <p className="mt-1 text-sm text-muted-foreground">
          Paket lunas yang belum dipakai untuk event apa pun. Tidak ada yang kedaluwarsa —
          ini daftar entitlement yang sudah dibayar tapi belum ditagih pemiliknya.
          <code className="mx-1 rounded bg-[var(--bg-soft)] px-1 py-0.5 text-[12px]">
            plan-orders:remind-idle
          </code>
          mengingatkan mereka lewat email setiap hari.
        </p>

        {idleQuery.isPending && <Skeleton className="mt-3 h-24 rounded-xl" />}

        {idleQuery.data && idle.length === 0 && (
          <p className="mt-3 text-sm text-muted-foreground">
            Tidak ada kredit yang menganggur. Semua paket lunas sudah dipakai.
          </p>
        )}

        <div className="mt-3 grid gap-2">
          {idle.map((credit) => (
            <Card key={credit.id} className="flex flex-wrap items-center justify-between gap-3 p-4">
              <div className="min-w-0">
                <p className="font-semibold">
                  {credit.organization?.name ?? "Organisasi dihapus"}
                </p>
                <p className="text-sm text-muted-foreground">
                  {credit.plan?.name ?? "Paket dihapus"} &middot; {rupiah(credit.amount)} &middot;{" "}
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
                {/* The escape hatch for a plan bought against the wrong event.
                    It lived only as an API call until now. */}
                <Button variant="outline" size="sm" onClick={() => setReassigning(credit)}>
                  Pakai untuk event lain
                </Button>
              </div>
            </Card>
          ))}
        </div>
      </section>

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
          title="Bukti transfer paket"
          description={`${reviewing.organization?.name ?? "Organisasi dihapus"} · ${
            reviewing.invoice_number ?? "tanpa nomor invoice"
          }`}
          proofUrl={reviewing.payment_proof_url}
          uploadedAt={reviewing.payment_proof_uploaded_at}
          details={[
            { label: "Organisasi", value: reviewing.organization?.name ?? "Organisasi dihapus" },
            {
              label: "Paket",
              value: `${reviewing.plan?.name ?? "Paket dihapus"} · ${
                reviewing.event?.name ?? "Belum dipakai"
              }`,
            },
            { label: "Invoice", value: reviewing.invoice_number ?? "—" },
            { label: "Jumlah", value: <span className="font-bold">{rupiah(reviewing.amount)}</span> },
          ]}
          consequence="Menerima berarti mengaktifkan paket ini seketika. Cocokkan dulu jumlah dan waktu transfer dengan mutasi rekening flo-event — tidak ada uang yang masuk otomatis."
          rejectPlaceholder="Alasan penolakan (dilihat organizer)"
          busy={busy}
          onApprove={() => approve.mutate(reviewing.id)}
          onReject={(text) => reject.mutate({ id: reviewing.id, text })}
        />
      )}
    </div>
  );
}
