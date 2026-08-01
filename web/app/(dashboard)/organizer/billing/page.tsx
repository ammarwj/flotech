"use client";

import { useState } from "react";
import Link from "next/link";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { Building2, Eye, PackageCheck, ReceiptText } from "lucide-react";

import {
  getPlanOrderDocument,
  getPlanOrders,
  payPlanOrder,
  submitPlanOrderProof,
} from "@/lib/api/organizations";
import { parseApiError } from "@/lib/api/errors";
import { checkoutOutcome } from "@/lib/checkout";
import { unconsumedOrders } from "@/lib/plan";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { rupiah } from "@/lib/labels";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { EmptyState } from "@/components/shared/empty-state";
import { PlanOrderStatusBadge } from "@/components/shared/status-badge";
import { RedirectIfAdmin } from "@/components/auth/redirect-if-admin";
import { ManualTransferPanel } from "@/components/payment/manual-transfer-panel";
import {
  DocumentPreviewDialog,
  type PreviewDocument,
} from "@/components/subscription/document-preview-dialog";
import type { EventPlanOrder } from "@/types/api";

const dateTime = (iso: string | null) =>
  iso ? new Date(iso).toLocaleString("id-ID", { dateStyle: "medium", timeStyle: "short" }) : "—";

/**
 * Plan purchases: what is waiting to be spent, what still needs paying, and the
 * receipts for everything else.
 *
 * There is no "current plan" card. An organization has no plan — its events do —
 * and an organizer can be running a Starter event and a Professional one at the
 * same time. Nothing expires either, so the old "Sisa N hari" badges left with
 * the clock they were counting.
 */
export default function BillingPage() {
  const qc = useQueryClient();
  const { org, orgId, isLoading: orgLoading } = useActiveOrg();
  const [busyId, setBusyId] = useState<string | null>(null);
  const [preview, setPreview] = useState<PreviewDocument | null>(null);

  const closePreview = () => {
    setPreview((current) => {
      if (current) URL.revokeObjectURL(current.url);
      return null;
    });
  };

  const ordersQuery = useQuery({
    queryKey: ["plan-orders", orgId],
    queryFn: () => getPlanOrders(orgId!),
    enabled: !!orgId,
  });

  const pay = useMutation({
    mutationFn: (order: EventPlanOrder) => payPlanOrder(orgId!, order.id),
    onSuccess: (res) => {
      const outcome = checkoutOutcome(res);

      if (outcome === "redirect") {
        window.location.assign(res.redirect_url!);
        return;
      }

      qc.invalidateQueries({ queryKey: ["organizations"] });
      qc.invalidateQueries({ queryKey: ["plan-orders"] });

      if (outcome === "manual") {
        toast.info("Selesaikan transfer manual", {
          description: "Instruksi transfer sudah muncul di atas halaman ini.",
        });
        return;
      }

      if (outcome === "failed") {
        toast.error("Pembayaran belum bisa dibuka", {
          description: "Coba lagi beberapa saat lagi.",
        });
        return;
      }

      toast.success("Tagihan lunas", { description: "Paketnya siap dipakai untuk satu event." });
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal membuka pembayaran.").message),
    onSettled: () => setBusyId(null),
  });

  const proof = useMutation({
    mutationFn: ({ order, url }: { order: EventPlanOrder; url: string }) =>
      submitPlanOrderProof(orgId!, order.id, url),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["organizations"] });
      qc.invalidateQueries({ queryKey: ["plan-orders"] });
      toast.success("Bukti terkirim", { description: "Admin flo-event akan memverifikasinya." });
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal mengirim bukti pembayaran.").message),
  });

  const openDocument = useMutation({
    mutationFn: async ({ order, kind }: { order: EventPlanOrder; kind: "invoice" | "receipt" }) => {
      const { blob, fileName } = await getPlanOrderDocument(orgId!, order.id, kind);
      return {
        title: kind === "receipt" ? "Kwitansi" : "Invoice",
        fileName,
        blob,
        url: URL.createObjectURL(blob),
      };
    },
    onSuccess: (doc) => setPreview(doc),
    onError: () => toast.error("Gagal memuat dokumen."),
    onSettled: () => setBusyId(null),
  });

  if (orgLoading) {
    return <Skeleton className="h-[200px] rounded-xl" />;
  }

  // The organizer layout already bounces org-less users to onboarding; this only
  // catches super admins (who own no org) and narrows `org` for the code below.
  if (!org) {
    return (
      <EmptyState
        icon={Building2}
        title="Belum ada organisasi"
        description="Buat organisasi dulu sebelum membeli paket."
        action={
          <Button asChild>
            <Link href="/onboarding">Buat organisasi</Link>
          </Button>
        }
      />
    );
  }

  const orders = ordersQuery.data ?? [];
  // The bill the organizer still has to settle by hand. `payment_method` is
  // snapshotted per row, so this survives the gateway coming back up.
  const pendingManual =
    orders.find((o) => o.status === "past_due" && o.payment_method === "manual") ?? null;
  const credits = unconsumedOrders(orders);

  return (
    <div>
      <RedirectIfAdmin />

      <PageHeader
        title="Pembelian Paket"
        description="Paket yang siap dipakai, tagihan yang belum lunas, dan riwayat pembelianmu."
        actions={
          <Button asChild>
            <Link href="/organizer/plans">Beli paket</Link>
          </Button>
        }
      />

      {pendingManual && (
        <ManualTransferPanel
          payee="platform"
          bankAccount={pendingManual.bank_account}
          amount={pendingManual.amount}
          deadlineAt={pendingManual.payment_deadline_at}
          awaitingVerification={pendingManual.awaiting_verification}
          rejectedReason={pendingManual.rejected_reason}
          pending={proof.isPending}
          onSubmit={(url) => proof.mutate({ order: pendingManual, url })}
        />
      )}

      {/* The surface that stops an abandoned checkout from being lost money. A
          credit never expires, so without this it would sit invisible in the
          history below. */}
      {credits.length > 0 && (
        <section className="mb-8">
          <h2 className="mb-3 text-lg font-bold" style={{ fontFamily: "var(--font-display)" }}>
            Paket siap dipakai
          </h2>
          <div className="grid gap-3">
            {credits.map((order) => (
              <Card
                key={order.id}
                className="flex flex-wrap items-center justify-between gap-4 p-4"
                style={{
                  borderColor: "color-mix(in srgb, var(--success) 40%, transparent)",
                  background: "color-mix(in srgb, var(--success) 5%, transparent)",
                }}
              >
                <div className="flex min-w-0 items-start gap-3">
                  <PackageCheck className="mt-0.5 h-5 w-5 shrink-0 text-[var(--success)]" />
                  <div className="min-w-0">
                    <p className="font-semibold">{order.plan?.name ?? "Paket dihapus"}</p>
                    <p className="mt-0.5 text-sm text-muted-foreground">
                      {rupiah(order.amount)} &middot; dibeli {dateTime(order.paid_at)} &middot; belum
                      dipakai
                    </p>
                  </div>
                </div>
                <Button asChild size="sm">
                  <Link href={`/organizer/events/new?plan_order_id=${order.id}`}>Buat event</Link>
                </Button>
              </Card>
            ))}
          </div>
        </section>
      )}

      <section>
        <h2 className="mb-3 text-lg font-bold" style={{ fontFamily: "var(--font-display)" }}>
          Riwayat Pembelian
        </h2>

        {ordersQuery.isLoading ? (
          <Skeleton className="h-[88px] rounded-xl" />
        ) : orders.length === 0 ? (
          <EmptyState
            icon={ReceiptText}
            title="Belum ada pembelian"
            description="Setiap pembelian paket akan tercatat di sini beserta invoice dan kwitansinya."
            action={
              <Button asChild>
                <Link href="/organizer/plans">Beli paket</Link>
              </Button>
            }
          />
        ) : (
          <div className="grid gap-3">
            {orders.map((order) => (
              <Card key={order.id} className="flex flex-wrap items-center justify-between gap-4 p-4">
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="font-semibold">{order.plan?.name ?? "Paket dihapus"}</span>
                    <PlanOrderStatusBadge
                      status={order.status}
                      awaitingVerification={order.awaiting_verification}
                    />
                    <span className="font-semibold tabular-nums">{rupiah(order.amount)}</span>
                  </div>
                  <p className="mt-1 text-sm text-muted-foreground">
                    {order.invoice_number ?? "—"} &middot;{" "}
                    {order.event ? (
                      <Link
                        href={`/organizer/events/${order.event.id}`}
                        className="underline underline-offset-2"
                      >
                        {order.event.name}
                      </Link>
                    ) : (
                      "Belum dipakai"
                    )}{" "}
                    &middot; {order.paid_at ? `Dibayar ${dateTime(order.paid_at)}` : "Belum dibayar"}
                  </p>
                </div>

                <div className="flex flex-wrap gap-2">
                  {/* Manual bills are settled through the panel above, not a
                      Snap redirect — and pay() would re-derive the rail. */}
                  {order.status === "past_due" && order.payment_method === "gateway" && (
                    <Button
                      size="sm"
                      disabled={busyId === order.id}
                      onClick={() => {
                        setBusyId(order.id);
                        pay.mutate(order);
                      }}
                    >
                      Bayar sekarang
                    </Button>
                  )}
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={busyId === order.id}
                    onClick={() => {
                      setBusyId(order.id);
                      openDocument.mutate({ order, kind: "invoice" });
                    }}
                  >
                    <Eye className="h-4 w-4" />
                    Invoice
                  </Button>
                  {order.paid_at && (
                    <Button
                      variant="outline"
                      size="sm"
                      disabled={busyId === order.id}
                      onClick={() => {
                        setBusyId(order.id);
                        openDocument.mutate({ order, kind: "receipt" });
                      }}
                    >
                      <Eye className="h-4 w-4" />
                      Kwitansi
                    </Button>
                  )}
                </div>
              </Card>
            ))}
          </div>
        )}
      </section>

      <DocumentPreviewDialog document={preview} onClose={closePreview} />
    </div>
  );
}
