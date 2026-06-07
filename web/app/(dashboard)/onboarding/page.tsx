"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { useMutation, useQuery } from "@tanstack/react-query";
import { AxiosError } from "axios";
import { toast } from "sonner";
import { Check, Building2, CreditCard } from "lucide-react";

import { getPublicPlans } from "@/lib/api/plans";
import { createOrganization, checkoutSubscription } from "@/lib/api/organizations";
import { useAuthStore } from "@/stores/auth-store";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { cn } from "@/lib/utils";
import type { Plan } from "@/types/api";

const rupiah = (n: number) => "Rp " + new Intl.NumberFormat("id-ID").format(n);

const PLAN_COLORS: Record<string, string> = {
  free: "var(--plan-free)",
  starter: "var(--plan-starter)",
  pro: "var(--plan-pro)",
  professional: "var(--plan-professional)",
};

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
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated);

  const [step, setStep] = useState<1 | 2>(1);
  const [orgName, setOrgName] = useState("");
  const [orgId, setOrgId] = useState<string | null>(null);
  const [cycle, setCycle] = useState<"monthly" | "yearly">("monthly");

  useEffect(() => {
    if (!isAuthenticated) router.replace("/login");
  }, [isAuthenticated, router]);

  const plansQuery = useQuery({ queryKey: ["public-plans"], queryFn: getPublicPlans });

  const createOrg = useMutation({
    mutationFn: () => createOrganization({ name: orgName }),
    onSuccess: (org) => {
      toast.success("Organisasi berhasil dibuat!");
      setOrgId(org.id);
      setStep(2);
    },
    onError: (err) => {
      const msg = err instanceof AxiosError ? (err.response?.data?.message ?? "Gagal membuat organisasi.") : "Gagal membuat organisasi.";
      toast.error(msg);
    },
  });

  const checkout = useMutation({
    mutationFn: (plan: Plan) => checkoutSubscription(orgId!, plan.id, cycle),
    onSuccess: (res) => {
      if (res.redirect_url) {
        window.location.assign(res.redirect_url);
      } else {
        toast.success("Langganan aktif. Selamat datang!");
        router.push("/dashboard");
      }
    },
    onError: (err) => {
      const msg = err instanceof AxiosError ? (err.response?.data?.message ?? "Gagal memproses pembayaran.") : "Gagal memproses pembayaran.";
      toast.error(msg);
    },
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
          <div className="mb-5 inline-flex items-center gap-1 rounded-full border border-border bg-[var(--surface)] p-1 text-sm font-semibold">
            {(["monthly", "yearly"] as const).map((c) => (
              <button
                key={c}
                onClick={() => setCycle(c)}
                className={cn(
                  "rounded-full px-4 py-1.5 transition-colors",
                  cycle === c ? "bg-[var(--brand-600)] text-white" : "text-muted-foreground hover:text-foreground"
                )}
              >
                {c === "monthly" ? "Bulanan" : "Tahunan"}
              </button>
            ))}
          </div>

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
            {plansQuery.data?.map((plan) => {
              const price = cycle === "yearly" ? plan.price_yearly : plan.price_monthly;
              const color = PLAN_COLORS[plan.slug] ?? "var(--brand-600)";
              const featured = plan.slug === "pro";
              return (
                <Card
                  key={plan.id}
                  className={cn(
                    "relative flex flex-col p-5",
                    featured && "ring-1 ring-[var(--brand-600)]"
                  )}
                >
                  {featured && (
                    <span className="absolute -top-2.5 left-5 rounded-full bg-[var(--brand-600)] px-2.5 py-0.5 text-[11px] font-bold text-white">
                      Populer
                    </span>
                  )}
                  <div className="flex items-center gap-2">
                    <span className="h-2.5 w-2.5 rounded-full" style={{ background: color }} />
                    <span className="font-bold" style={{ fontFamily: "var(--font-display)" }}>
                      {plan.name}
                    </span>
                  </div>
                  <div className="mt-3 text-2xl font-extrabold" style={{ fontFamily: "var(--font-display)" }}>
                    {price === 0 ? "Gratis" : rupiah(price)}
                    {price > 0 && (
                      <span className="text-sm font-medium text-muted-foreground">
                        /{cycle === "yearly" ? "thn" : "bln"}
                      </span>
                    )}
                  </div>
                  <p className="mt-1 min-h-[36px] text-xs text-muted-foreground">{plan.description}</p>
                  <Button
                    className="mt-4"
                    variant={featured ? "default" : "outline"}
                    disabled={checkout.isPending}
                    onClick={() => checkout.mutate(plan)}
                  >
                    {checkout.isPending ? "Memproses…" : "Pilih paket"}
                  </Button>
                </Card>
              );
            })}
          </div>
        </div>
      )}
    </div>
  );
}
