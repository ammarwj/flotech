"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { Banknote, Copy } from "lucide-react";

import {
  completeWithdrawal,
  getAdminWithdrawals,
  processWithdrawal,
  rejectWithdrawal,
  type CompleteWithdrawalInput,
} from "@/lib/api/admin-wallet";
import { parseApiError } from "@/lib/api/errors";
import { rupiah } from "@/lib/labels";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { EmptyState } from "@/components/shared/empty-state";
import { WithdrawalStatusBadge } from "@/components/shared/status-badge";
import { CompleteWithdrawalDialog } from "@/components/admin/complete-withdrawal-dialog";
import type { Withdrawal, WithdrawalStatus } from "@/types/api";

const TABS: { value: WithdrawalStatus; label: string }[] = [
  { value: "pending", label: "Menunggu" },
  { value: "processing", label: "Diproses" },
  { value: "completed", label: "Selesai" },
  { value: "rejected", label: "Ditolak" },
];

const dateTime = (iso: string | null) =>
  iso ? new Date(iso).toLocaleString("id-ID", { dateStyle: "medium", timeStyle: "short" }) : "—";

export default function AdminWithdrawalsPage() {
  const qc = useQueryClient();
  const [tab, setTab] = useState<WithdrawalStatus>("pending");
  const [completing, setCompleting] = useState<Withdrawal | null>(null);

  const query = useQuery({
    queryKey: ["admin-withdrawals", tab],
    queryFn: () => getAdminWithdrawals(tab),
  });

  const invalidate = () => qc.invalidateQueries({ queryKey: ["admin-withdrawals"] });
  const fail = (fallback: string) => (err: unknown) =>
    toast.error(parseApiError(err, fallback).message);

  const processMutation = useMutation({
    mutationFn: processWithdrawal,
    onSuccess: () => {
      invalidate();
      toast.success("Ditandai sedang diproses");
    },
    onError: fail("Gagal memproses penarikan."),
  });

  const completeMutation = useMutation({
    mutationFn: ({ id, values }: { id: string; values: CompleteWithdrawalInput }) =>
      completeWithdrawal(id, values),
    onSuccess: () => {
      setCompleting(null);
      invalidate();
      toast.success("Penarikan ditandai selesai");
    },
    onError: fail("Gagal menyelesaikan penarikan."),
  });

  const rejectMutation = useMutation({
    mutationFn: ({ id, reason }: { id: string; reason: string }) => rejectWithdrawal(id, reason),
    onSuccess: () => {
      invalidate();
      toast.success("Penarikan ditolak, dana dikembalikan ke saldo organizer");
    },
    onError: fail("Gagal menolak penarikan."),
  });

  const reject = (w: Withdrawal) => {
    const reason = window.prompt(`Alasan menolak penarikan ${w.reference}?`);
    if (reason?.trim()) rejectMutation.mutate({ id: w.id, reason: reason.trim() });
  };

  const copy = (text: string) => {
    navigator.clipboard.writeText(text);
    toast.success("Nomor rekening disalin");
  };

  const items = query.data ?? [];

  return (
    <>
      <PageHeader
        title="Penarikan Dana"
        description="Transfer manual ke rekening organizer lewat m-banking, lalu catat buktinya di sini. Menandai selesai tidak mengirim uang secara otomatis."
      />

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
            <Skeleton key={i} className="h-[104px] rounded-xl" />
          ))}
        </div>
      ) : items.length === 0 ? (
        <EmptyState
          icon={Banknote}
          title="Tidak ada penarikan"
          description="Permintaan penarikan dari organizer akan muncul di sini."
        />
      ) : (
        <div className="grid gap-3">
          {items.map((w) => (
            <Card key={w.id} className="flex flex-wrap items-start justify-between gap-4 p-5">
              <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                  <span className="text-lg font-bold tabular-nums">{rupiah(w.amount)}</span>
                  <WithdrawalStatusBadge status={w.status} />
                  <span className="text-xs text-muted-foreground">{w.reference}</span>
                </div>

                <p className="mt-1 text-sm font-medium">{w.organization_name ?? "—"}</p>

                <div className="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted-foreground">
                  <span>{w.bank_name}</span>
                  <span>&middot;</span>
                  <button
                    onClick={() => copy(w.account_number)}
                    className="inline-flex items-center gap-1.5 font-mono font-semibold text-foreground transition-colors hover:text-[var(--brand-600)]"
                  >
                    {w.account_number}
                    <Copy className="h-3.5 w-3.5" />
                  </button>
                  <span>&middot;</span>
                  <span>{w.account_holder}</span>
                </div>

                <p className="mt-1 text-xs text-muted-foreground">
                  Diajukan {dateTime(w.created_at)} &middot; biaya admin {rupiah(w.admin_fee)}{" "}
                  &middot; total dipotong {rupiah(w.total_debit)}
                </p>

                {w.note && <p className="mt-1 text-sm text-muted-foreground">“{w.note}”</p>}
                {w.admin_note && (
                  <p className="mt-1 text-sm text-[var(--danger)]">{w.admin_note}</p>
                )}
              </div>

              <div className="flex flex-wrap gap-2">
                {w.status === "pending" && (
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={processMutation.isPending}
                    onClick={() => processMutation.mutate(w.id)}
                  >
                    Proses
                  </Button>
                )}
                {(w.status === "pending" || w.status === "processing") && (
                  <>
                    <Button size="sm" onClick={() => setCompleting(w)}>
                      Tandai Selesai
                    </Button>
                    <Button
                      variant="ghost"
                      size="sm"
                      disabled={rejectMutation.isPending}
                      onClick={() => reject(w)}
                    >
                      Tolak
                    </Button>
                  </>
                )}
                {w.proof_url && (
                  <Button asChild variant="outline" size="sm">
                    <a href={w.proof_url} target="_blank" rel="noreferrer">
                      Bukti
                    </a>
                  </Button>
                )}
              </div>
            </Card>
          ))}
        </div>
      )}

      <CompleteWithdrawalDialog
        key={completing?.id}
        withdrawal={completing}
        pending={completeMutation.isPending}
        onClose={() => setCompleting(null)}
        onSubmit={(values) =>
          completing && completeMutation.mutate({ id: completing.id, values })
        }
      />
    </>
  );
}
