"use client";

import { useState } from "react";
import Link from "next/link";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import {
  Banknote,
  Building2,
  Clock,
  Hourglass,
  Landmark,
  ReceiptText,
  TrendingUp,
  Wallet as WalletIcon,
} from "lucide-react";

import {
  cancelWithdrawal,
  createBankAccount,
  createWithdrawal,
  getBankAccounts,
  getWallet,
  getWalletTransactions,
  getWithdrawals,
  type BankAccountInput,
} from "@/lib/api/wallet";
import { parseApiError, type FieldErrors } from "@/lib/api/errors";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { rupiah, WALLET_TX_CATEGORY_LABELS } from "@/lib/labels";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { EmptyState } from "@/components/shared/empty-state";
import { WalletTxStatusBadge, WithdrawalStatusBadge } from "@/components/shared/status-badge";
import { RedirectIfAdmin } from "@/components/auth/redirect-if-admin";
import { BankAccountForm } from "@/components/wallet/bank-account-form";
import { WithdrawDialog } from "@/components/wallet/withdraw-dialog";
import type { LucideIcon } from "lucide-react";

function StatCard({
  icon: Icon,
  label,
  value,
  hint,
  danger,
}: {
  icon: LucideIcon;
  label: string;
  value: string;
  hint?: string;
  danger?: boolean;
}) {
  return (
    <Card className="p-5">
      <div className="flex items-center gap-2 text-sm text-muted-foreground">
        <Icon className="h-4 w-4" />
        {label}
      </div>
      <p
        className="mt-2 text-2xl font-bold tabular-nums"
        style={{
          fontFamily: "var(--font-display)",
          color: danger ? "var(--danger)" : undefined,
        }}
      >
        {value}
      </p>
      {hint && <p className="mt-1 text-xs text-muted-foreground">{hint}</p>}
    </Card>
  );
}

const dateTime = (iso: string | null) =>
  iso ? new Date(iso).toLocaleString("id-ID", { dateStyle: "medium", timeStyle: "short" }) : "—";

export default function WalletPage() {
  const qc = useQueryClient();
  const { org, orgId, isLoading: orgLoading, hasNoOrg } = useActiveOrg();

  const [withdrawOpen, setWithdrawOpen] = useState(false);
  const [bankErrors, setBankErrors] = useState<FieldErrors>({});
  const [withdrawErrors, setWithdrawErrors] = useState<FieldErrors>({});

  const walletQuery = useQuery({
    queryKey: ["wallet", orgId],
    queryFn: () => getWallet(orgId!),
    enabled: !!orgId,
  });
  const txQuery = useQuery({
    queryKey: ["wallet-transactions", orgId],
    queryFn: () => getWalletTransactions(orgId!),
    enabled: !!orgId,
  });
  const banksQuery = useQuery({
    queryKey: ["bank-accounts", orgId],
    queryFn: () => getBankAccounts(orgId!),
    enabled: !!orgId,
  });
  const withdrawalsQuery = useQuery({
    queryKey: ["withdrawals", orgId],
    queryFn: () => getWithdrawals(orgId!),
    enabled: !!orgId,
  });

  const invalidate = () => {
    qc.invalidateQueries({ queryKey: ["wallet", orgId] });
    qc.invalidateQueries({ queryKey: ["wallet-transactions", orgId] });
    qc.invalidateQueries({ queryKey: ["withdrawals", orgId] });
  };

  const bankMutation = useMutation({
    mutationFn: (values: BankAccountInput) => createBankAccount(orgId!, values),
    onSuccess: () => {
      setBankErrors({});
      qc.invalidateQueries({ queryKey: ["bank-accounts", orgId] });
      qc.invalidateQueries({ queryKey: ["wallet", orgId] });
      toast.success("Rekening bank disimpan");
    },
    onError: (err) => {
      const parsed = parseApiError(err, "Gagal menyimpan rekening.");
      setBankErrors(parsed.fieldErrors);
      if (Object.keys(parsed.fieldErrors).length === 0) toast.error(parsed.message);
    },
  });

  const withdrawMutation = useMutation({
    mutationFn: (values: { amount: number; note?: string }) => createWithdrawal(orgId!, values),
    onSuccess: () => {
      setWithdrawErrors({});
      setWithdrawOpen(false);
      invalidate();
      toast.success("Permintaan penarikan dikirim", {
        description: "Dana ditahan sampai admin menyelesaikan transfer.",
      });
    },
    onError: (err) => {
      const parsed = parseApiError(err, "Gagal mengajukan penarikan.");
      setWithdrawErrors(parsed.fieldErrors);
      if (Object.keys(parsed.fieldErrors).length === 0) toast.error(parsed.message);
    },
  });

  const cancelMutation = useMutation({
    mutationFn: (id: string) => cancelWithdrawal(orgId!, id),
    onSuccess: () => {
      invalidate();
      toast.success("Penarikan dibatalkan, dana kembali ke saldo tersedia");
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal membatalkan penarikan.").message),
  });

  if (orgLoading) {
    return (
      <div className="grid gap-3">
        <Skeleton className="h-[120px] rounded-xl" />
        <Skeleton className="h-[120px] rounded-xl" />
      </div>
    );
  }

  if (hasNoOrg || !org) {
    return (
      <EmptyState
        icon={Building2}
        title="Belum ada organisasi"
        description="Buat organisasi dulu sebelum bisa menerima dan menarik dana."
        action={
          <Button asChild>
            <Link href="/onboarding">Buat organisasi</Link>
          </Button>
        }
      />
    );
  }

  const wallet = walletQuery.data;
  const primaryBank = banksQuery.data?.find((b) => b.is_primary) ?? null;
  const transactions = txQuery.data?.items ?? [];
  const withdrawals = withdrawalsQuery.data ?? [];

  const negative = !!wallet && wallet.balance_available < 0;
  const belowMinimum =
    !!wallet && wallet.balance_available < wallet.rules.minimum_withdrawal + wallet.rules.admin_fee;

  // Say *why* the button is off rather than just disabling it.
  const blockedReason = !wallet
    ? "Memuat saldo…"
    : !primaryBank
      ? "Tambahkan rekening bank dulu."
      : wallet.has_active_withdrawal
        ? "Masih ada penarikan yang sedang diproses."
        : negative
          ? "Saldo minus karena refund."
          : belowMinimum
            ? `Saldo tersedia belum mencapai ${rupiah(wallet.rules.minimum_withdrawal + wallet.rules.admin_fee)} (termasuk biaya admin).`
            : null;

  return (
    <>
      <RedirectIfAdmin />

      <PageHeader
        title="Dompet"
        description="Pendapatan tiket dan biaya pendaftaran dikumpulkan platform, lalu dicairkan ke rekeningmu."
        actions={
          <Button disabled={!!blockedReason} onClick={() => setWithdrawOpen(true)}>
            <Banknote className="mr-2 h-4 w-4" />
            Tarik Dana
          </Button>
        }
      />

      {walletQuery.isLoading || !wallet ? (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {[0, 1, 2, 3].map((i) => (
            <Skeleton key={i} className="h-[110px] rounded-xl" />
          ))}
        </div>
      ) : (
        <>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard
              icon={WalletIcon}
              label="Saldo Tersedia"
              value={rupiah(wallet.balance_available)}
              hint="Siap ditarik"
              danger={negative}
            />
            <StatCard
              icon={Hourglass}
              label="Saldo Tertahan"
              value={rupiah(wallet.balance_pending)}
              hint="Cair setelah event selesai"
            />
            <StatCard
              icon={Clock}
              label="Sedang Diproses"
              value={rupiah(wallet.balance_on_hold)}
              hint="Menunggu transfer admin"
            />
            <StatCard
              icon={TrendingUp}
              label="Total Ditarik"
              value={rupiah(wallet.total_withdrawn)}
              hint={`Total pendapatan ${rupiah(wallet.total_earned)}`}
            />
          </div>

          {negative && (
            <Card className="mt-4 border-[var(--danger)]/40 bg-[var(--danger)]/5 p-4">
              <p className="text-sm font-semibold text-[var(--danger)]">Saldo minus</p>
              <p className="mt-1 text-sm text-muted-foreground">
                Ada refund atas dana yang sudah kamu tarik. Penarikan dikunci sampai saldo kembali
                positif — pendapatan berikutnya akan menutup selisih ini otomatis.
              </p>
            </Card>
          )}

          {blockedReason && !negative && (
            <p className="mt-4 text-sm text-muted-foreground">{blockedReason}</p>
          )}
        </>
      )}

      <section className="mt-10">
        <h2 className="mb-3 text-lg font-bold" style={{ fontFamily: "var(--font-display)" }}>
          Rekening Pencairan
        </h2>
        {banksQuery.isLoading ? (
          <Skeleton className="h-[88px] rounded-xl" />
        ) : (
          <BankAccountForm
            current={primaryBank}
            pending={bankMutation.isPending}
            fieldErrors={bankErrors}
            onSubmit={(values) => bankMutation.mutate(values)}
          />
        )}
      </section>

      <section className="mt-10">
        <h2 className="mb-3 text-lg font-bold" style={{ fontFamily: "var(--font-display)" }}>
          Riwayat Penarikan
        </h2>
        {withdrawalsQuery.isLoading ? (
          <Skeleton className="h-[88px] rounded-xl" />
        ) : withdrawals.length === 0 ? (
          <EmptyState
            icon={Landmark}
            title="Belum ada penarikan"
            description="Penarikan yang kamu ajukan akan muncul di sini beserta bukti transfernya."
          />
        ) : (
          <div className="grid gap-3">
            {withdrawals.map((w) => (
              <Card key={w.id} className="flex flex-wrap items-center justify-between gap-4 p-4">
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="font-semibold tabular-nums">{rupiah(w.amount)}</span>
                    <WithdrawalStatusBadge status={w.status} />
                    <span className="text-xs text-muted-foreground">{w.reference}</span>
                  </div>
                  <p className="mt-1 text-sm text-muted-foreground">
                    {w.bank_name} &middot; {w.account_number} &middot; biaya admin{" "}
                    {rupiah(w.admin_fee)} &middot; {dateTime(w.created_at)}
                  </p>
                  {w.admin_note && (
                    <p className="mt-1 text-sm text-[var(--danger)]">{w.admin_note}</p>
                  )}
                </div>
                <div className="flex gap-2">
                  {w.proof_url && (
                    <Button asChild variant="outline" size="sm">
                      <a href={w.proof_url} target="_blank" rel="noreferrer">
                        Bukti transfer
                      </a>
                    </Button>
                  )}
                  {w.status === "pending" && (
                    <Button
                      variant="ghost"
                      size="sm"
                      disabled={cancelMutation.isPending}
                      onClick={() => cancelMutation.mutate(w.id)}
                    >
                      Batalkan
                    </Button>
                  )}
                </div>
              </Card>
            ))}
          </div>
        )}
      </section>

      <section className="mt-10">
        <h2 className="mb-3 text-lg font-bold" style={{ fontFamily: "var(--font-display)" }}>
          Mutasi Dompet
        </h2>
        {txQuery.isLoading ? (
          <Skeleton className="h-[88px] rounded-xl" />
        ) : transactions.length === 0 ? (
          <EmptyState
            icon={ReceiptText}
            title="Belum ada mutasi"
            description="Penjualan tiket dan biaya pendaftaran yang lunas akan tercatat di sini."
          />
        ) : (
          <div className="grid gap-2">
            {transactions.map((tx) => (
              <Card key={tx.id} className="flex flex-wrap items-center justify-between gap-3 p-4">
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="font-medium">{WALLET_TX_CATEGORY_LABELS[tx.category]}</span>
                    <WalletTxStatusBadge status={tx.status} />
                  </div>
                  <p className="mt-0.5 truncate text-sm text-muted-foreground">
                    {tx.description ?? tx.event_name ?? "—"} &middot; {dateTime(tx.created_at)}
                  </p>
                </div>
                <div className="text-right">
                  <p
                    className="font-semibold tabular-nums"
                    style={{
                      color: tx.type === "credit" ? "var(--success)" : "var(--danger)",
                    }}
                  >
                    {tx.type === "credit" ? "+" : "−"}
                    {rupiah(tx.amount)}
                  </p>
                  {tx.fee_amount > 0 && (
                    <p className="text-xs text-muted-foreground">
                      biaya {rupiah(tx.fee_amount)} dari {rupiah(tx.gross_amount)}
                    </p>
                  )}
                </div>
              </Card>
            ))}
          </div>
        )}
      </section>

      {wallet && primaryBank && (
        <WithdrawDialog
          wallet={wallet}
          bank={primaryBank}
          open={withdrawOpen}
          pending={withdrawMutation.isPending}
          fieldErrors={withdrawErrors}
          onClose={() => setWithdrawOpen(false)}
          onSubmit={(values) => withdrawMutation.mutate(values)}
        />
      )}
    </>
  );
}
