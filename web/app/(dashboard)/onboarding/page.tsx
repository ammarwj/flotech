"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { useMutation, useQuery } from "@tanstack/react-query";
import { AxiosError } from "axios";

import { getPublicPlans } from "@/lib/api/plans";
import { createOrganization, checkoutSubscription } from "@/lib/api/organizations";
import { useAuthStore } from "@/stores/auth-store";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import type { Plan } from "@/types/api";

const rupiah = (n: number) => "Rp " + new Intl.NumberFormat("id-ID").format(n);

export default function OnboardingPage() {
  const router = useRouter();
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated);

  const [step, setStep] = useState<1 | 2>(1);
  const [orgName, setOrgName] = useState("");
  const [orgId, setOrgId] = useState<string | null>(null);
  const [cycle, setCycle] = useState<"monthly" | "yearly">("monthly");
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!isAuthenticated) router.replace("/login");
  }, [isAuthenticated, router]);

  const plansQuery = useQuery({ queryKey: ["public-plans"], queryFn: getPublicPlans });

  const createOrg = useMutation({
    mutationFn: () => createOrganization({ name: orgName }),
    onSuccess: (org) => {
      setOrgId(org.id);
      setStep(2);
    },
    onError: (err) =>
      setError(err instanceof AxiosError ? (err.response?.data?.message ?? "Gagal") : "Gagal"),
  });

  const checkout = useMutation({
    mutationFn: (plan: Plan) => checkoutSubscription(orgId!, plan.id, cycle),
    onSuccess: (res) => {
      if (res.redirect_url) {
        window.location.assign(res.redirect_url);
      } else {
        router.push("/dashboard");
      }
    },
    onError: (err) =>
      setError(err instanceof AxiosError ? (err.response?.data?.message ?? "Gagal") : "Gagal"),
  });

  return (
    <div className="mx-auto max-w-3xl">
      <h1 className="text-2xl font-bold" style={{ fontFamily: "var(--font-display)" }}>
        {step === 1 ? "Buat organisasi" : "Pilih paket"}
      </h1>
      <p className="mt-2 text-muted-foreground">
        {step === 1
          ? "Organisasi adalah ruang kerja untuk semua turnamenmu."
          : "Mulai dari Free, upgrade kapan saja."}
      </p>

      {error && <p className="mt-4 text-sm text-destructive">{error}</p>}

      {step === 1 && (
        <form
          onSubmit={(e) => {
            e.preventDefault();
            setError(null);
            createOrg.mutate();
          }}
          className="mt-6 grid max-w-md gap-4"
        >
          <div className="grid gap-2">
            <Label htmlFor="org">Nama organisasi</Label>
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
      )}

      {step === 2 && (
        <div className="mt-6">
          <div className="mb-4 inline-flex items-center gap-2 rounded-md border border-border p-1 text-sm">
            <button
              onClick={() => setCycle("monthly")}
              className={`rounded px-3 py-1 ${cycle === "monthly" ? "bg-primary text-primary-foreground" : ""}`}
            >
              Bulanan
            </button>
            <button
              onClick={() => setCycle("yearly")}
              className={`rounded px-3 py-1 ${cycle === "yearly" ? "bg-primary text-primary-foreground" : ""}`}
            >
              Tahunan
            </button>
          </div>

          {plansQuery.isLoading && <p className="text-muted-foreground">Memuat paket…</p>}
          {plansQuery.isError && (
            <p className="text-destructive">Gagal memuat paket. Pastikan API berjalan.</p>
          )}

          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {plansQuery.data?.map((plan) => {
              const price = cycle === "yearly" ? plan.price_yearly : plan.price_monthly;
              return (
                <div key={plan.id} className="flex flex-col rounded-lg border border-border bg-card p-5">
                  <div className="font-semibold" style={{ fontFamily: "var(--font-display)" }}>
                    {plan.name}
                  </div>
                  <div className="mt-2 text-2xl font-extrabold" style={{ fontFamily: "var(--font-display)" }}>
                    {price === 0 ? "Gratis" : rupiah(price)}
                  </div>
                  <p className="mt-1 text-xs text-muted-foreground">{plan.description}</p>
                  <Button
                    className="mt-4"
                    variant={plan.slug === "pro" ? "default" : "outline"}
                    disabled={checkout.isPending}
                    onClick={() => {
                      setError(null);
                      checkout.mutate(plan);
                    }}
                  >
                    {checkout.isPending ? "Memproses…" : "Pilih"}
                  </Button>
                </div>
              );
            })}
          </div>
        </div>
      )}
    </div>
  );
}
