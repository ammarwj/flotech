"use client";

import { Suspense, useState } from "react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
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
import { PillTabs } from "@/components/event/pill-tabs";
import { WalletTxStatusBadge, WithdrawalStatusBadge } from "@/components/shared/status-badge";
import { RedirectIfAdmin } from "@/components/auth/redirect-if-admin";
import { BankAccountForm } from "@/components/wallet/bank-account-form";
import { WithdrawDialog } from "@/components/wallet/withdraw-dialog";
import { PaymentProofDialog } from "@/components/payment/payment-proof-dialog";
import type { Withdrawal } from "@/types/api";
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

/** Three lists that share a page but not a job. `bank` is the default, so it carries no query param. */
const TABS = [
  { key: "bank", label: "Rekening Pencairan", icon: Landmark },
  { key: "withdrawals", label: "Riwayat Penarikan", icon: Banknote },
  { key: "transactions", label: "Mutasi Dompet", icon: ReceiptText },
];

function WalletPage() {
  const qc = useQueryClient();
  const router = useRouter();
  const params = useSearchParams();
  const { org, orgId, isLoading: orgLoading } = useActiveOrg();

  const [withdrawOpen, setWithdrawOpen] = useState(false);
  const [bankErrors, setBankErrors] = useState<FieldErrors>({});
  const [withdrawErrors, setWithdrawErrors] = useState<FieldErrors>({});
  // The transfer receipt on screen. One dialog for the whole list rather than
  // one per row — mounting a dialog per withdrawal to show at most one is waste.
  const [proof, setProof] = useState<Withdrawal | null>(null);

  // The open tab lives in the URL, not in state: a reload — or a link shared
  // with someone — has to land back on the same list. Defaults to the bank
  // account, since an organizer with nowhere to be paid cannot withdraw at all.
  const tab = TABS.some((t) => t.key === params.get("tab")) ? params.get("tab")! : "bank";

  const setTab = (key: string) => {
    const next = new URLSearchParams(params.toString());
    if (key === "bank") next.delete("tab");
    else next.set("tab", key);
    const qs = next.toString();
    router.replace(qs ? `/organizer/wallet?${qs}` : "/organizer/wallet", { scroll: false });
  };

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

  // The organizer layout already bounces org-less users to onboarding; this only
  // catches super admins (who own no org) and narrows `org` for the code below.
  if (!org) {
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

  const minimumTotal = wallet
    ? wallet.rules.minimum_withdrawal + wallet.rules.admin_fee
    : 0;

  // Money is in the wallet, just not withdrawable yet. Saying "belum mencapai"
  // here reads as "you haven't sold enough", which is the wrong thing to tell
  // an organizer sitting on millions in held funds — they would go looking for
  // missing sales instead of waiting for their event to finish.
  const heldCoversMinimum =
    !!wallet && wallet.balance_pending > 0 && wallet.balance_available + wallet.balance_pending >= minimumTotal;

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
            ? heldCoversMinimum
              ? `${rupiah(wallet.balance_pending)} masih tertahan sampai event-nya selesai. Dana cair otomatis setelah itu, lalu bisa ditarik.`
              : `Saldo tersedia ${rupiah(wallet.balance_available)} — penarikan bisa dilakukan mulai ${rupiah(minimumTotal)} (minimum ${rupiah(wallet.rules.minimum_withdrawal)} + biaya admin ${rupiah(wallet.rules.admin_fee)}).`
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

      {/* Three lists that share a page but not a job: where the money goes,
          what has been sent, and where every rupiah came from. Stacked, the
          balance cards at the top were pushed off screen by history nobody
          had asked to read. */}
      <div className="mt-8">
        <PillTabs
          items={TABS}
          activeKey={tab}
          onSelect={setTab}
        />
      </div>

      <div className="mt-5">
      {tab === "bank" && (
        <section>
        {banksQuery.isLoading ? (
          <Skeleton className="h-[88px] rounded-xl" />
        ) : (
          <BankAccountForm
            current={primaryBank}
            pending={bankMutation.isPending}
            fieldErrors={bankErrors}
            // mutateAsync, not mutate: the form closes itself on success and
            // has to stay open on failure, so it needs the outcome.
            onSubmit={(values) => bankMutation.mutateAsync(values)}
          />
        )}
        </section>
      )}

      {tab === "withdrawals" && (
        <section>
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
              <Card
                key={w.id}
                className="flex flex-col gap-3 p-4 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between sm:gap-4"
              >
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="font-semibold tabular-nums">{rupiah(w.amount)}</span>
                    <WithdrawalStatusBadge status={w.status} />
                    <span className="text-xs text-muted-foreground">{w.reference}</span>
                  </div>
                  {/* One fact per line on a phone; the middot run only reads as
                      a list when it fits on one. */}
                  <div className="mt-1 flex flex-col text-sm text-muted-foreground sm:block">
                    <span>
                      {w.bank_name} &middot; {w.account_number}
                    </span>
                    <span className="hidden sm:inline"> &middot; </span>
                    <span>
                      biaya admin {rupiah(w.admin_fee)} &middot; {dateTime(w.created_at)}
                    </span>
                  </div>
                  {w.admin_note && (
                    <p className="mt-1 text-sm text-[var(--danger)]">{w.admin_note}</p>
                  )}
                </div>
                <div className="flex gap-2">
                  {/* Opens in place rather than a new tab: the proof is a
                      signed object-storage link, so a raw tab drops the
                      organizer on a bare image with no idea which withdrawal
                      it belongs to. */}
                  {w.proof_url && (
                    <Button variant="outline" size="sm" onClick={() => setProof(w)}>
                      <ReceiptText className="h-4 w-4" />
                      Bukti transfer
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
      )}

      {tab === "transactions" && (
        <section>
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
              // Stacks on a phone instead of wrapping: the amount used to wrap
              // under the description while keeping text-right, leaving it
              // stranded against the far edge with a ragged gap beside it.
              // Below sm it reads as two left-aligned lines.
              <Card
                key={tx.id}
                className="flex flex-col gap-1 p-4 sm:flex-row sm:items-center sm:justify-between sm:gap-3"
              >
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="font-medium">{WALLET_TX_CATEGORY_LABELS[tx.category]}</span>
                    <WalletTxStatusBadge status={tx.status} />
                  </div>
                  {/* Wraps rather than truncates on a phone: a description cut
                      to "Penjualan 2 tiket — Turnamen…" says less than the
                      extra line costs. */}
                  <p className="mt-0.5 text-sm text-muted-foreground sm:truncate">
                    {tx.description ?? tx.event_name ?? "—"} &middot; {dateTime(tx.created_at)}
                  </p>
                </div>
                <div className="shrink-0 sm:text-right">
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
      )}
      </div>

      {/* Read-only: no onApprove/onReject, because the transfer is already
          done and there is nothing here to rule on. */}
      <PaymentProofDialog
        open={!!proof}
        onOpenChange={(next) => !next && setProof(null)}
        title="Bukti transfer"
        description={proof ? `Penarikan ${proof.reference}` : undefined}
        proofUrl={proof?.proof_url ?? null}
        uploadedAt={proof?.completed_at ?? null}
        details={
          proof
            ? [
                { label: "Jumlah", value: <b>{rupiah(proof.amount)}</b> },
                { label: "Biaya admin", value: rupiah(proof.admin_fee) },
                { label: "Rekening", value: `${proof.bank_name} · ${proof.account_number}` },
                { label: "Atas nama", value: proof.account_holder },
              ]
            : []
        }
      />

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

export default function Page() {
  // useSearchParams() needs a Suspense boundary or the build fails.
  return (
    <Suspense fallback={null}>
      <WalletPage />
    </Suspense>
  );
}
