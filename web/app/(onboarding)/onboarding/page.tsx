"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { Check, Building2, CreditCard } from "lucide-react";

import { getPublicPlans } from "@/lib/api/plans";
import { createOrganization, checkoutSubscription } from "@/lib/api/organizations";
import { parseApiError } from "@/lib/api/errors";
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
import { cn } from "@/lib/utils";
import type { Plan } from "@/types/api";

function Steps({ step }: { step: 1 | 2 }) {
  const items = [
    { n: 1, label: "Organisasi", icon: Building2 },
    { n: 2, label: "Paket", icon: CreditCard },
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
            {i === 0 && <div className="h-px w-8 bg-border" />}
          </div>
        );
      })}
    </div>
  );
}

export default function OnboardingPage() {
  const router = useRouter();
  const qc = useQueryClient();
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated);

  const [step, setStep] = useState<1 | 2>(1);
  const [orgName, setOrgName] = useState("");
  const [orgId, setOrgId] = useState<string | null>(null);
  const [cycle, setCycle] = useState<BillingCycle>("monthly");
  const [pendingPlanId, setPendingPlanId] = useState<string | null>(null);

  useEffect(() => {
    if (!isAuthenticated) router.replace("/login");
  }, [isAuthenticated, router]);

  // Step 1 creates an organization, and nothing on the API side stops a second
  // one — but useActiveOrg() only ever reads data[0], so a duplicate would be an
  // invisible ghost. Bounce anyone who already has an org (back button, bookmark)
  // before they can submit the form. Only guards step 1: once step 1 succeeds the
  // org exists by design and the user must be allowed through to pick a plan.
  const { org } = useActiveOrg();

  useEffect(() => {
    if (step === 1 && org) router.replace("/organizer");
  }, [step, org, router]);

  const plansQuery = useQuery({ queryKey: ["public-plans"], queryFn: getPublicPlans });

  const createOrg = useMutation({
    mutationFn: () => createOrganization({ name: orgName }),
    onSuccess: (org) => {
      toast.success("Organisasi berhasil dibuat!");
      qc.invalidateQueries({ queryKey: ["organizations"] });
      setOrgId(org.id);
      setStep(2);
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal membuat organisasi.").message),
  });

  const checkout = useMutation({
    mutationFn: (plan: Plan) => checkoutSubscription(orgId!, plan.id, cycle),
    onSuccess: (res) => {
      if (res.redirect_url) {
        window.location.assign(res.redirect_url);
        return;
      }
      // Dev/mock: subscription auto-activated and the plan switched.
      qc.invalidateQueries({ queryKey: ["organizations"] });
      qc.invalidateQueries({ queryKey: ["subscriptions"] });
      toast.success("Langganan aktif. Selamat datang!");
      router.push("/organizer");
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal memproses pembayaran.").message),
    onSettled: () => setPendingPlanId(null),
  });

  return (
    <div className="mx-auto max-w-4xl">
      <Steps step={step} />
      <PageHeader
        title={step === 1 ? "Buat organisasi" : "Pilih paket"}
        description={
          step === 1
            ? "Organisasi adalah ruang kerja untuk semua turnamenmu."
            : "Mulai dari Free, upgrade kapan saja sesuai kebutuhan."
        }
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
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
              {[0, 1, 2, 3].map((i) => (
                <Skeleton key={i} className="h-56 w-full rounded-xl" />
              ))}
            </div>
          )}
          {plansQuery.isError && (
            <p className="text-sm text-[var(--danger)]">Gagal memuat paket. Pastikan API berjalan.</p>
          )}

          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
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
          </div>
        </div>
      )}
    </div>
  );
}
