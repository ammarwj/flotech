"use client";

import { useState } from "react";
import Link from "next/link";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { Building2, CalendarClock, Check, CreditCard, Eye, ReceiptText } from "lucide-react";

import {
  getSubscriptionDocument,
  getSubscriptions,
  paySubscription,
} from "@/lib/api/organizations";
import { parseApiError } from "@/lib/api/errors";
import { formatPlanFeature } from "@/lib/plan";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { BILLING_CYCLE_LABELS, rupiah } from "@/lib/labels";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { EmptyState } from "@/components/shared/empty-state";
import { SubscriptionStatusBadge } from "@/components/shared/status-badge";
import { RedirectIfAdmin } from "@/components/auth/redirect-if-admin";
import {
  DocumentPreviewDialog,
  type PreviewDocument,
} from "@/components/subscription/document-preview-dialog";
import type { Subscription } from "@/types/api";

const dateOnly = (iso: string | null) =>
  iso ? new Date(iso).toLocaleDateString("id-ID", { dateStyle: "medium" }) : "—";

const dateTime = (iso: string | null) =>
  iso ? new Date(iso).toLocaleString("id-ID", { dateStyle: "medium", timeStyle: "short" }) : "—";

/** Whole days from now until `iso`; negative once it's in the past. */
function daysUntil(iso: string | null): number | null {
  if (!iso) return null;
  return Math.ceil((new Date(iso).getTime() - Date.now()) / 86_400_000);
}

export default function SubscriptionPage() {
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

  const subsQuery = useQuery({
    queryKey: ["subscriptions", orgId],
    queryFn: () => getSubscriptions(orgId!),
    enabled: !!orgId,
  });

  const pay = useMutation({
    mutationFn: (sub: Subscription) => paySubscription(orgId!, sub.id),
    onSuccess: (res) => {
      if (res.redirect_url) {
        window.location.assign(res.redirect_url);
        return;
      }
      // Dev/mock: no gateway configured, so the invoice settled immediately.
      qc.invalidateQueries({ queryKey: ["organizations"] });
      qc.invalidateQueries({ queryKey: ["subscriptions"] });
      toast.success("Tagihan lunas", { description: "Paket kamu sudah aktif." });
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal membuka pembayaran.").message),
    onSettled: () => setBusyId(null),
  });

  const openDocument = useMutation({
    mutationFn: async ({ sub, kind }: { sub: Subscription; kind: "invoice" | "receipt" }) => {
      const { blob, fileName } = await getSubscriptionDocument(orgId!, sub.id, kind);
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
        description="Buat organisasi dulu sebelum mengelola langganan."
        action={
          <Button asChild>
            <Link href="/onboarding">Buat organisasi</Link>
          </Button>
        }
      />
    );
  }

  const subs = subsQuery.data ?? [];
  const plan = org.plan;
  const currentCycle = subs.find((s) => s.status === "active")?.billing_cycle ?? null;
  const remaining = daysUntil(org.plan_expires_at);
  const expiringSoon = remaining !== null && remaining >= 0 && remaining <= 14;
  const expired = remaining !== null && remaining < 0;

  return (
    <div>
      <RedirectIfAdmin />

      <PageHeader
        title="Langganan"
        description="Status paket, tagihan, dan bukti pembayaranmu."
        actions={
          <Button asChild>
            <Link href="/organizer/upgrade">Ubah paket</Link>
          </Button>
        }
      />

      <Card className="p-5">
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div>
            <div className="flex items-center gap-2 text-sm text-muted-foreground">
              <CreditCard className="h-4 w-4" />
              Paket saat ini
            </div>
            <p className="mt-2 text-2xl font-bold" style={{ fontFamily: "var(--font-display)" }}>
              {plan?.name ?? "Tanpa paket"}
            </p>
            {currentCycle && (
              <p className="mt-1 text-sm text-muted-foreground">
                Siklus {BILLING_CYCLE_LABELS[currentCycle]}
              </p>
            )}
          </div>

          <div className="text-right">
            <div className="flex items-center justify-end gap-2 text-sm text-muted-foreground">
              <CalendarClock className="h-4 w-4" />
              {org.plan_expires_at ? "Aktif sampai" : "Masa aktif"}
            </div>
            <p className="mt-2 font-semibold tabular-nums">
              {org.plan_expires_at ? dateOnly(org.plan_expires_at) : "Tanpa batas waktu"}
            </p>
            {expired && (
              <Badge variant="danger" className="mt-2">
                Kedaluwarsa
              </Badge>
            )}
            {expiringSoon && (
              <Badge variant="warning" className="mt-2">
                Sisa {remaining} hari
              </Badge>
            )}
          </div>
        </div>

        {plan?.feature_details && plan.feature_details.length > 0 && (
          <ul className="mt-5 grid gap-2 border-t border-border pt-5 sm:grid-cols-2">
            {plan.feature_details
              .filter((f) => f.included)
              .map((f) => (
                <li key={f.key} className="flex items-start gap-2 text-sm">
                  <Check className="mt-0.5 h-4 w-4 shrink-0 text-[var(--success)]" />
                  <span>{formatPlanFeature(f)}</span>
                </li>
              ))}
          </ul>
        )}
      </Card>

      {(expired || expiringSoon) && (
        <Card className="mt-4 border-[var(--warning)]/40 bg-[var(--warning)]/5 p-4">
          <p className="text-sm font-semibold">
            {expired ? "Paket kamu sudah kedaluwarsa" : "Paket kamu akan segera berakhir"}
          </p>
          <p className="mt-1 text-sm text-muted-foreground">
            Perpanjang sekarang supaya event aktifmu tidak terhenti.
          </p>
        </Card>
      )}

      <section className="mt-10">
        <h2 className="mb-3 text-lg font-bold" style={{ fontFamily: "var(--font-display)" }}>
          Riwayat Transaksi
        </h2>

        {subsQuery.isLoading ? (
          <Skeleton className="h-[88px] rounded-xl" />
        ) : subs.length === 0 ? (
          <EmptyState
            icon={ReceiptText}
            title="Belum ada transaksi"
            description="Setiap pembelian paket akan tercatat di sini beserta invoice dan kwitansinya."
          />
        ) : (
          <div className="grid gap-3">
            {subs.map((sub) => (
              <Card key={sub.id} className="flex flex-wrap items-center justify-between gap-4 p-4">
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="font-semibold">{sub.plan?.name ?? "Paket dihapus"}</span>
                    <SubscriptionStatusBadge status={sub.status} />
                    <span className="font-semibold tabular-nums">{rupiah(sub.amount)}</span>
                  </div>
                  <p className="mt-1 text-sm text-muted-foreground">
                    {sub.invoice_number ?? "—"} &middot;{" "}
                    {BILLING_CYCLE_LABELS[sub.billing_cycle]} &middot;{" "}
                    {sub.paid_at ? `Dibayar ${dateTime(sub.paid_at)}` : dateTime(sub.starts_at)}
                  </p>
                </div>

                <div className="flex flex-wrap gap-2">
                  {sub.status === "past_due" && (
                    <Button
                      size="sm"
                      disabled={busyId === sub.id}
                      onClick={() => {
                        setBusyId(sub.id);
                        pay.mutate(sub);
                      }}
                    >
                      Bayar sekarang
                    </Button>
                  )}
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={busyId === sub.id}
                    onClick={() => {
                      setBusyId(sub.id);
                      openDocument.mutate({ sub, kind: "invoice" });
                    }}
                  >
                    <Eye className="h-4 w-4" />
                    Invoice
                  </Button>
                  {sub.paid_at && (
                    <Button
                      variant="outline"
                      size="sm"
                      disabled={busyId === sub.id}
                      onClick={() => {
                        setBusyId(sub.id);
                        openDocument.mutate({ sub, kind: "receipt" });
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
