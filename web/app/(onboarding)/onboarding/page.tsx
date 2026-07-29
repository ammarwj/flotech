"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { Check, Building2, CreditCard, Receipt } from "lucide-react";

import { getPublicPlans } from "@/lib/api/plans";
import {
  createOrganization,
  checkoutSubscription,
  getSubscriptions,
  submitSubscriptionProof,
} from "@/lib/api/organizations";
import { parseApiError } from "@/lib/api/errors";
import { checkoutOutcome } from "@/lib/checkout";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { getMaxYearlyDiscount } from "@/lib/plan";
import { useAuthStore } from "@/stores/auth-store";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import {
  BillingCycleToggle,
  PlanCard,
  type BillingCycle,
} from "@/components/subscription/plan-card";
import { PlanGrid } from "@/components/subscription/plan-grid";
import { ManualTransferPanel } from "@/components/payment/manual-transfer-panel";
import { cn } from "@/lib/utils";
import type { Plan, Subscription } from "@/types/api";

function Steps({ step }: { step: 1 | 2 | 3 }) {
  const items = [
    { n: 1, label: "Organisasi", icon: Building2 },
    { n: 2, label: "Paket", icon: CreditCard },
    { n: 3, label: "Pembayaran", icon: Receipt },
  ];
  return (
    <div className="mb-8 flex items-center gap-3">
      {items.map(({ n, label, icon: Icon }, i) => {
        const active = step >= n;
        return (
          <div key={n} className="flex items-center gap-3">
            <div
              className={cn(
                "flex items-center gap-2 rounded-full px-3 py-1.5 text-sm font-semibold transition-colors",
                active ? "bg-[var(--tint)] text-[var(--brand-600)]" : "bg-[var(--bg-soft)] text-muted-foreground"
              )}
            >
              {step > n ? <Check className="h-4 w-4" /> : <Icon className="h-4 w-4" />}
              {label}
            </div>
            {i < items.length - 1 && <div className="h-px w-8 bg-border" />}
          </div>
        );
      })}
    </div>
  );
}

const STEP_COPY: Record<1 | 2 | 3, { title: string; description: string }> = {
  1: {
    title: "Buat organisasi",
    description: "Organisasi adalah ruang kerja untuk semua turnamenmu.",
  },
  2: {
    title: "Pilih paket",
    description: "Pilih paket untuk mengaktifkan organisasimu. Upgrade kapan saja sesuai kebutuhan.",
  },
  3: {
    title: "Selesaikan pembayaran",
    description:
      "Paketmu aktif setelah transfer diverifikasi admin flo-event. Kamu tetap bisa membuka dashboard sambil menunggu.",
  },
};

export default function OnboardingPage() {
  const router = useRouter();
  const qc = useQueryClient();
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated);

  const [orgName, setOrgName] = useState("");
  const [createdOrgId, setCreatedOrgId] = useState<string | null>(null);
  const [cycle, setCycle] = useState<BillingCycle>("monthly");
  const [pendingPlanId, setPendingPlanId] = useState<string | null>(null);

  useEffect(() => {
    if (!isAuthenticated) router.replace("/login");
  }, [isAuthenticated, router]);

  const { org } = useActiveOrg();

  // An org without a plan is an abandoned checkout: it has no entitlements at
  // all until one is bought, so resume at step 2 instead of bouncing the owner
  // to a dashboard they can't use. Deriving the step (rather than storing it)
  // keeps the freshly created org and the refetched one from disagreeing.
  const pendingOrgId = createdOrgId ?? (org && !org.plan ? org.id : null);

  // Step 3 exists only while the payment gateway is off: the plan was chosen but
  // has to be paid for by bank transfer and approved by a super admin first.
  // Polled because that approval happens elsewhere; the effect below takes over
  // the moment `org.plan` lands.
  const subsQuery = useQuery({
    queryKey: ["subscriptions", pendingOrgId],
    queryFn: () => getSubscriptions(pendingOrgId!),
    enabled: !!pendingOrgId,
    refetchInterval: 30_000,
  });

  // `payment_method` is snapshotted per row, so this survives a super admin
  // switching the gateway back on halfway through onboarding.
  const pendingManual =
    subsQuery.data?.find((s) => s.status === "past_due" && s.payment_method === "manual") ?? null;

  const step: 1 | 2 | 3 = !pendingOrgId ? 1 : pendingManual ? 3 : 2;

  // Step 1 creates an organization, and nothing on the API side stops a second
  // one — but useActiveOrg() only ever reads data[0], so a duplicate would be an
  // invisible ghost. Anyone already onboarded (org + plan) has no business here.
  useEffect(() => {
    if (org?.plan) router.replace("/organizer");
  }, [org, router]);

  const plansQuery = useQuery({ queryKey: ["public-plans"], queryFn: getPublicPlans });

  const createOrg = useMutation({
    mutationFn: () => createOrganization({ name: orgName }),
    onSuccess: (org) => {
      toast.success("Organisasi berhasil dibuat!");
      qc.invalidateQueries({ queryKey: ["organizations"] });
      setCreatedOrgId(org.id);
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal membuat organisasi.").message),
  });

  const checkout = useMutation({
    mutationFn: (plan: Plan) => checkoutSubscription(pendingOrgId!, plan.id, cycle),
    onSuccess: (res) => {
      const outcome = checkoutOutcome(res);

      if (outcome === "redirect") {
        window.location.assign(res.redirect_url!);
        return;
      }

      qc.invalidateQueries({ queryKey: ["organizations"] });
      qc.invalidateQueries({ queryKey: ["subscriptions"] });

      if (outcome === "manual") {
        // No navigation: the step derives itself from the bill that now exists.
        return;
      }

      if (outcome === "failed") {
        toast.error("Pembayaran belum bisa dibuka", {
          description: "Tagihan sudah terbit. Coba lagi dari halaman Langganan.",
        });
        return;
      }

      toast.success("Langganan aktif. Selamat datang!");
      router.push("/organizer");
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal memproses pembayaran.").message),
    onSettled: () => setPendingPlanId(null),
  });

  const proof = useMutation({
    mutationFn: ({ sub, url }: { sub: Subscription; url: string }) =>
      submitSubscriptionProof(pendingOrgId!, sub.id, url),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["subscriptions"] });
      // Also the org: `subscription_awaiting_verification` rides on that
      // payload, and it is what the dashboard's pending banner reads.
      qc.invalidateQueries({ queryKey: ["organizations"] });
      toast.success("Bukti terkirim", { description: "Admin flo-event akan memverifikasinya." });
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal mengirim bukti pembayaran.").message),
  });

  return (
    <div className="mx-auto max-w-4xl">
      <Steps step={step} />
      <PageHeader
        title={STEP_COPY[step].title}
        description={STEP_COPY[step].description}
      />

      {step === 1 && (
        <Card className="max-w-md p-6">
          <form
            onSubmit={(e) => {
              e.preventDefault();
              createOrg.mutate();
            }}
            className="grid gap-4"
          >
            <div className="grid gap-2">
              <Label htmlFor="org" className="font-semibold">
                Nama organisasi
              </Label>
              <Input
                id="org"
                value={orgName}
                onChange={(e) => setOrgName(e.target.value)}
                placeholder="Jakarta Sports EO"
                required
              />
            </div>
            <Button type="submit" size="lg" disabled={createOrg.isPending || orgName.length < 2}>
              {createOrg.isPending ? "Membuat…" : "Lanjutkan"}
            </Button>
          </form>
        </Card>
      )}

      {step === 2 && (
        <div>
          <BillingCycleToggle
            cycle={cycle}
            onChange={setCycle}
            discount={getMaxYearlyDiscount(plansQuery.data)}
          />

          {plansQuery.isLoading && (
            <PlanGrid count={4}>
              {[0, 1, 2, 3].map((i) => (
                <Skeleton key={i} className="h-56 w-full rounded-xl" />
              ))}
            </PlanGrid>
          )}
          {plansQuery.isError && (
            <p className="text-sm text-[var(--danger)]">Gagal memuat paket. Pastikan API berjalan.</p>
          )}

          <PlanGrid count={plansQuery.data?.length ?? 0}>
            {plansQuery.data?.map((plan) => (
              <PlanCard
                key={plan.id}
                plan={plan}
                cycle={cycle}
                isPending={pendingPlanId === plan.id}
                disabled={checkout.isPending}
                onSelect={(p) => {
                  setPendingPlanId(p.id);
                  checkout.mutate(p);
                }}
              />
            ))}
          </PlanGrid>
        </div>
      )}

      {step === 3 && pendingManual && (
        <div className="max-w-lg">
          <ManualTransferPanel
            payee="platform"
            bankAccount={pendingManual.bank_account}
            amount={pendingManual.amount}
            deadlineAt={pendingManual.payment_deadline_at}
            awaitingVerification={pendingManual.awaiting_verification}
            rejectedReason={pendingManual.rejected_reason}
            pending={proof.isPending}
            onSubmit={(url) => proof.mutate({ sub: pendingManual, url })}
          />
          <Button variant="outline" onClick={() => router.push("/organizer")}>
            Lihat dashboard
          </Button>
          <p className="mt-2 text-xs text-muted-foreground">
            Fitur organisasi masih terkunci sampai pembayaranmu diverifikasi.
          </p>
        </div>
      )}
    </div>
  );
}
