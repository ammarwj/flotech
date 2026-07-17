"use client";

import { useState } from "react";
import { useParams } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { Check, ExternalLink, Inbox, X } from "lucide-react";

import {
  approveTeamPayment,
  approveTicketPayment,
  getPendingPayments,
  rejectTeamPayment,
  rejectTicketPayment,
} from "@/lib/api/tickets";
import { parseApiError } from "@/lib/api/errors";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { rupiah } from "@/lib/labels";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";

/** What the two queues have in common, so one row component renders both. */
type Row = {
  id: string;
  kind: "ticket" | "team";
  title: string;
  subtitle: string;
  amount: number;
  proofUrl: string | null;
  uploadedAt: string | null;
};

export default function EventPaymentsPage() {
  const params = useParams<{ id: string }>();
  const qc = useQueryClient();
  const { org, orgId } = useActiveOrg();
  const [rejecting, setRejecting] = useState<string | null>(null);
  const [reason, setReason] = useState("");

  const query = useQuery({
    queryKey: ["event-payments", orgId, params.id],
    queryFn: () => getPendingPayments(orgId!, params.id),
    enabled: !!orgId,
  });

  const done = (message: string) => {
    qc.invalidateQueries({ queryKey: ["event-payments", orgId, params.id] });
    setRejecting(null);
    setReason("");
    toast.success(message);
  };

  // The queue refetches after either action, so the returned row is unused —
  // typing these as void keeps the ticket/team union out of the signature.
  const approve = useMutation<void, Error, Row>({
    mutationFn: async (row) => {
      if (row.kind === "ticket") await approveTicketPayment(orgId!, params.id, row.id);
      else await approveTeamPayment(orgId!, params.id, row.id);
    },
    onSuccess: () => done("Pembayaran diterima."),
    onError: (err) => toast.error(parseApiError(err, "Gagal menerima pembayaran.").message),
  });

  const reject = useMutation<void, Error, { row: Row; text: string }>({
    mutationFn: async ({ row, text }) => {
      if (row.kind === "ticket") await rejectTicketPayment(orgId!, params.id, row.id, text);
      else await rejectTeamPayment(orgId!, params.id, row.id, text);
    },
    onSuccess: () => done("Bukti ditolak. Pembeli dapat mengunggah ulang."),
    onError: (err) => toast.error(parseApiError(err, "Gagal menolak bukti.").message),
  });

  const rows: Row[] = [
    ...(query.data?.tickets ?? []).map<Row>((o) => ({
      id: o.id,
      kind: "ticket",
      title: o.buyer_name,
      subtitle: `${o.quantity} tiket · ${o.category?.name ?? "-"}`,
      amount: o.total_price,
      proofUrl: o.payment_proof_url,
      uploadedAt: o.payment_proof_uploaded_at,
    })),
    ...(query.data?.teams ?? []).map<Row>((t) => ({
      id: t.id,
      kind: "team",
      title: t.name,
      subtitle: `Pendaftaran tim · ${t.category?.name ?? "-"}`,
      amount: t.payment_amount,
      proofUrl: t.payment_proof_url,
      uploadedAt: t.payment_proof_uploaded_at,
    })),
  ];

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
              <div>
                <p className="font-semibold">{row.title}</p>
                <p className="text-sm text-muted-foreground">{row.subtitle}</p>
                <p className="mt-1 text-sm font-bold">{rupiah(row.amount)}</p>
                {row.uploadedAt && (
                  <p className="mt-0.5 text-xs text-muted-foreground">
                    Diunggah {new Date(row.uploadedAt).toLocaleString("id-ID")}
                  </p>
                )}
              </div>
              <div className="flex flex-wrap gap-2">
                {row.proofUrl && (
                  <Button asChild size="sm" variant="outline">
                    <a href={row.proofUrl} target="_blank" rel="noopener noreferrer">
                      <ExternalLink className="h-4 w-4" />
                      Lihat bukti
                    </a>
                  </Button>
                )}
                <Button
                  size="sm"
                  disabled={busy}
                  onClick={() => approve.mutate(row)}
                >
                  <Check className="h-4 w-4" />
                  Terima
                </Button>
                <Button
                  size="sm"
                  variant="outline"
                  disabled={busy}
                  onClick={() => setRejecting(rejecting === row.id ? null : row.id)}
                >
                  <X className="h-4 w-4" />
                  Tolak
                </Button>
              </div>
            </div>

            {rejecting === row.id && (
              <div className="mt-3 flex flex-wrap items-center gap-2 border-t border-border pt-3">
                <Input
                  autoFocus
                  value={reason}
                  onChange={(e) => setReason(e.target.value)}
                  placeholder="Alasan penolakan (dilihat pembeli)"
                  className="min-w-[240px] flex-1"
                />
                <Button
                  size="sm"
                  variant="outline"
                  disabled={busy || reason.trim().length === 0}
                  onClick={() => reject.mutate({ row, text: reason.trim() })}
                >
                  Kirim penolakan
                </Button>
              </div>
            )}
          </Card>
        ))}
      </div>
    </div>
  );
}
