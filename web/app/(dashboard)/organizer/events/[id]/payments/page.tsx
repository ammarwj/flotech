"use client";

import { useState } from "react";
import { useParams } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { CheckCircle2, Inbox, ReceiptText } from "lucide-react";

import {
  approveRaceEntryPayment,
  approveTeamPayment,
  approveTicketPayment,
  getPendingPayments,
  getVerifiedPayments,
  rejectRaceEntryPayment,
  rejectTeamPayment,
  rejectTicketPayment,
  type PendingPayments,
} from "@/lib/api/tickets";
import { parseApiError } from "@/lib/api/errors";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { rupiah } from "@/lib/labels";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { PillTabs } from "@/components/event/pill-tabs";
import {
  PaymentProofDialog,
  type ProofDetail,
} from "@/components/payment/payment-proof-dialog";

/** What the three queues have in common, so one row component renders them all. */
type Row = {
  id: string;
  /**
   * Which rail this payment came in on. The three are independent streams that
   * share only the HasManualPayment shape — approving any of them is the same
   * act (money arrived) through a different endpoint, which is why the kind
   * travels on the row rather than splitting the page into three queues an
   * organizer would have to check separately.
   */
  kind: "ticket" | "team" | "race-entry";
  title: string;
  subtitle: string;
  amount: number;
  proofUrl: string | null;
  uploadedAt: string | null;
  /**
   * What the dialog lists beside the receipt. Built where the row is built, not
   * inside the dialog: the three streams describe themselves with different
   * fields — a ticket order has a quantity, a runner has a price period — and a
   * dialog branching on `kind` would be the one place that has to know all
   * three.
   */
  details: ProofDetail[];
  /**
   * When the receipt was accepted, and by whom. Null on a queue row by
   * construction — the server publishes neither until the relation is loaded,
   * which only the approval log does. It is what makes one row type serve both
   * lists instead of a second near-identical shape.
   */
  verifiedAt: string | null;
  verifiedBy: string | null;
};

/**
 * One payload — queue or history — flattened into rows, newest first within
 * each rail.
 *
 * Written once rather than twice because the two lists differ in nothing but
 * which endpoint filled them: two copies would be two chances for the history
 * to describe a payment differently from the queue that ruled on it, and the
 * subtitle is the line an organizer matches against their bank statement.
 */
function toRows(data: PendingPayments | undefined): Row[] {
  return [
    ...(data?.tickets ?? []).map<Row>((o) => ({
      id: o.id,
      kind: "ticket",
      title: o.buyer_name,
      subtitle: `${o.quantity} tiket · ${o.category?.name ?? "-"}`,
      amount: o.total_price,
      proofUrl: o.payment_proof_url,
      uploadedAt: o.payment_proof_uploaded_at,
      verifiedAt: o.verified_at,
      verifiedBy: o.verified_by ?? null,
      details: [
        { label: "Pembeli", value: o.buyer_name },
        // The contact, because a receipt that does not match the bank statement
        // is a question for the buyer, and that question is asked from here.
        { label: "Email", value: o.buyer_email || "—" },
        { label: "Tiket", value: `${o.quantity} × ${o.category?.name ?? "-"}` },
        {
          label: "Jumlah",
          value: <span className="font-bold">{rupiah(o.total_price)}</span>,
        },
      ],
    })),
    ...(data?.teams ?? []).map<Row>((t) => ({
      id: t.id,
      kind: "team",
      title: t.name,
      subtitle: `Pendaftaran tim · ${t.category?.name ?? "-"}`,
      amount: t.payment_amount,
      proofUrl: t.payment_proof_url,
      uploadedAt: t.payment_proof_uploaded_at,
      verifiedAt: t.verified_at,
      verifiedBy: t.verified_by ?? null,
      details: [
        { label: "Tim", value: t.name },
        { label: "Kategori", value: t.category?.name ?? "-" },
        { label: "Kontak", value: t.contact_name || "—" },
        {
          label: "Jumlah",
          value: <span className="font-bold">{rupiah(t.payment_amount)}</span>,
        },
      ],
    })),
    // Empty on every competition event, and read unconditionally anyway: a key
    // whose presence depends on the sport is one this page would have to defend
    // against in three places instead of none.
    ...(data?.race_entries ?? []).map<Row>((e) => ({
      id: e.id,
      kind: "race-entry",
      title: e.full_name,
      // The period, because it is what makes the amount checkable against the
      // price list — the same reason it is on the receipt.
      subtitle: `Lomba · ${e.category?.name ?? "-"} · ${e.period_name}`,
      amount: e.price,
      proofUrl: e.payment_proof_url,
      uploadedAt: e.payment_proof_uploaded_at,
      verifiedAt: e.verified_at,
      verifiedBy: e.verified_by ?? null,
      details: [
        { label: "Pelari", value: e.full_name },
        { label: "Email", value: e.email || "—" },
        { label: "Kategori", value: e.category?.name ?? "-" },
        // The window, for the same reason it is on the receipt: it is what
        // makes the amount checkable against the price list.
        { label: "Periode", value: e.period_name },
        {
          label: "Jumlah",
          value: <span className="font-bold">{rupiah(e.price)}</span>,
        },
      ],
    })),
  ];
}

/** "10 Nov 2026, 21.42" — the shape every other dashboard queue prints. */
const fmtDateTime = (iso: string) =>
  new Date(iso).toLocaleString("id-ID", { dateStyle: "medium", timeStyle: "short" });

export default function EventPaymentsPage() {
  const params = useParams<{ id: string }>();
  const qc = useQueryClient();
  const { org, orgId } = useActiveOrg();
  /**
   * The receipt on screen. The verdict used to be typed out here while the
   * proof was off in another browser tab — see PaymentProofDialog, whose whole
   * reason for existing is that the two halves of one decision belong on one
   * screen.
   */
  const [reviewing, setReviewing] = useState<Row | null>(null);
  const [tab, setTab] = useState("queue");

  const query = useQuery({
    queryKey: ["event-payments", orgId, params.id],
    queryFn: () => getPendingPayments(orgId!, params.id),
    enabled: !!orgId,
  });

  /**
   * Receipts already accepted. A tab beside the queue rather than a page of its
   * own, the same call the super admin's plan queue makes: the question it
   * answers — "did we already accept that transfer?" — is asked while looking
   * at a receipt that resembles one, and the answer is useless a click away.
   */
  const historyQuery = useQuery({
    queryKey: ["event-payments-history", orgId, params.id],
    queryFn: () => getVerifiedPayments(orgId!, params.id),
    enabled: !!orgId,
  });

  const done = (message: string) => {
    qc.invalidateQueries({ queryKey: ["event-payments", orgId, params.id] });
    // The row left the queue by landing here, so the log is stale too — a
    // receipt accepted a second ago must be findable, which is the one moment
    // somebody goes looking for it.
    qc.invalidateQueries({ queryKey: ["event-payments-history", orgId, params.id] });
    setReviewing(null);
    toast.success(message);
  };

  // The queue refetches after either action, so the returned row is unused —
  // typing these as void keeps the ticket/team union out of the signature.
  const approve = useMutation<void, Error, Row>({
    mutationFn: async (row) => {
      if (row.kind === "ticket") await approveTicketPayment(orgId!, params.id, row.id);
      else if (row.kind === "race-entry") await approveRaceEntryPayment(orgId!, params.id, row.id);
      else await approveTeamPayment(orgId!, params.id, row.id);
    },
    onSuccess: () => done("Pembayaran diterima."),
    onError: (err) => toast.error(parseApiError(err, "Gagal menerima pembayaran.").message),
  });

  const reject = useMutation<void, Error, { row: Row; text: string }>({
    mutationFn: async ({ row, text }) => {
      if (row.kind === "ticket") await rejectTicketPayment(orgId!, params.id, row.id, text);
      else if (row.kind === "race-entry") await rejectRaceEntryPayment(orgId!, params.id, row.id, text);
      else await rejectTeamPayment(orgId!, params.id, row.id, text);
    },
    onSuccess: () => done("Bukti ditolak. Pembeli dapat mengunggah ulang."),
    onError: (err) => toast.error(parseApiError(err, "Gagal menolak bukti.").message),
  });

  const rows = toRows(query.data);
  const history = toRows(historyQuery.data);

  const busy = approve.isPending || reject.isPending;

  return (
    <div>
      <PageHeader
        title="Verifikasi pembayaran"
        description={
          org?.payment_gateway_enabled
            ? "Transfer manual yang menunggu diperiksa. Uangnya masuk langsung ke rekeningmu — cocokkan dengan mutasi bank sebelum menerima."
            : "Payment gateway sedang dimatikan, jadi semua pembayaran masuk lewat transfer manual ke rekeningmu. Cocokkan dengan mutasi bank sebelum menerima."
        }
        backHref="/organizer/events"
        backLabel="Daftar event"
      />

      <PillTabs
        items={[
          {
            key: "queue",
            label: `Menunggu verifikasi${rows.length ? ` (${rows.length})` : ""}`,
            icon: Inbox,
          },
          { key: "history", label: "Riwayat", icon: CheckCircle2 },
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
                  <Skeleton key={i} className="h-24 rounded-xl" />
                ))}
              </div>
            )}

            {query.isError && (
              <p className="text-sm text-[var(--danger)]">Gagal memuat antrean pembayaran.</p>
            )}

            {query.data && rows.length === 0 && (
              <Card className="flex flex-col items-center gap-2 p-10 text-center">
                <Inbox className="h-8 w-8 text-muted-foreground" />
                <p className="font-semibold">Tidak ada yang menunggu verifikasi</p>
                <p className="text-sm text-muted-foreground">
                  Bukti transfer yang masuk akan muncul di sini.
                </p>
              </Card>
            )}

            <div className="grid gap-3">
              {rows.map((row) => (
                <Card key={`${row.kind}-${row.id}`} className="p-4">
                  <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                      <p className="font-semibold">{row.title}</p>
                      <p className="text-sm text-muted-foreground">{row.subtitle}</p>
                      <p className="mt-1 text-sm font-bold">{rupiah(row.amount)}</p>
                      {row.uploadedAt && (
                        <p className="mt-0.5 text-xs text-muted-foreground">
                          Diunggah {fmtDateTime(row.uploadedAt)}
                        </p>
                      )}
                    </div>
                    {/* One button, because there is one decision — and it can
                        only be made after looking at the receipt, which is
                        inside. Same shape as the super admin's plan queue. */}
                    <Button
                      size="sm"
                      disabled={busy}
                      onClick={() => setReviewing(row)}
                      className="w-full sm:w-auto"
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
              Bukti transfer yang sudah diterima. Yang ditolak tidak muncul di sini —
              pembayarnya masih bisa mengunggah ulang, jadi perkaranya belum selesai.
            </p>

            {historyQuery.isPending && <Skeleton className="h-24 rounded-xl" />}

            {historyQuery.isError && (
              <p className="text-sm text-[var(--danger)]">Gagal memuat riwayat verifikasi.</p>
            )}

            {historyQuery.data && history.length === 0 && (
              <p className="text-sm text-muted-foreground">
                Belum ada pembayaran yang diverifikasi di event ini.
              </p>
            )}

            <div className="grid gap-2">
              {history.map((row) => (
                <Card
                  key={`${row.kind}-${row.id}`}
                  className="flex flex-wrap items-center justify-between gap-3 p-4"
                >
                  <div className="min-w-0">
                    <p className="font-semibold">{row.title}</p>
                    <p className="text-sm text-muted-foreground">{row.subtitle}</p>
                    <p className="mt-0.5 text-sm font-bold">{rupiah(row.amount)}</p>
                  </div>
                  <div className="flex w-full flex-wrap items-center gap-3 sm:w-auto sm:gap-4">
                    <div className="text-sm sm:text-right">
                      <p className="flex items-center gap-1.5 font-medium text-[var(--success)] sm:justify-end">
                        <CheckCircle2 className="h-4 w-4" />
                        Diterima
                      </p>
                      <p className="mt-0.5 text-xs text-muted-foreground">
                        {row.verifiedAt ? fmtDateTime(row.verifiedAt) : "—"}
                        {row.verifiedBy ? ` · ${row.verifiedBy}` : ""}
                      </p>
                    </div>
                    {/* The same dialog the queue opens, minus the verdict — see
                        below: a settled row has no handlers, so there is nothing
                        to rule on twice. A receipt is looked up long after the
                        fact ("was this the transfer we accepted?") and the
                        answer is the image, not the row. */}
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => setReviewing(row)}
                      className="w-full sm:ml-auto sm:w-auto"
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
      </div>

      {reviewing && (
        <PaymentProofDialog
          open
          onOpenChange={(next) => !next && setReviewing(null)}
          title={reviewing.verifiedAt ? "Bukti transfer (sudah diterima)" : "Bukti transfer"}
          description={`${reviewing.title} · ${reviewing.subtitle}`}
          proofUrl={reviewing.proofUrl}
          uploadedAt={reviewing.uploadedAt}
          details={
            reviewing.verifiedAt
              ? [
                  ...reviewing.details,
                  {
                    label: "Diterima",
                    value: `${fmtDateTime(reviewing.verifiedAt)}${
                      reviewing.verifiedBy ? ` · ${reviewing.verifiedBy}` : ""
                    }`,
                  },
                ]
              : reviewing.details
          }
          /*
           * `verifiedAt` is the whole discriminator, and it is the row's own
           * column rather than which list it came from: a second piece of state
           * saying "this one is settled" could disagree with the row it
           * describes, and the disagreement would show as an Approve button
           * over a bill already accepted. Mirrors the server, where the same
           * column decides which of the two queries a row answers.
           */
          consequence={
            reviewing.verifiedAt
              ? undefined
              : "Menerima berarti pembayaran ini dianggap lunas seketika — tiket terbit, pendaftaran aktif. Cocokkan dulu jumlah dan waktu transfer dengan mutasi rekeningmu; tidak ada uang yang masuk otomatis."
          }
          rejectPlaceholder="Alasan penolakan (dilihat pembayar)"
          busy={busy}
          onApprove={reviewing.verifiedAt ? undefined : () => approve.mutate(reviewing)}
          onReject={
            reviewing.verifiedAt ? undefined : (text) => reject.mutate({ row: reviewing, text })
          }
        />
      )}
    </div>
  );
}
