"use client";

import { useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Building2, Check, X } from "lucide-react";
import { toast } from "sonner";

import { getPublicPlans } from "@/lib/api/plans";
import { checkoutSubscription } from "@/lib/api/organizations";
import { parseApiError } from "@/lib/api/errors";
import { formatPlanFeature, getActiveEventLimit } from "@/lib/plan";
import { useActiveOrg } from "@/lib/hooks/use-active-org";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { EmptyState } from "@/components/shared/empty-state";
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

export default function UpgradePage() {
  const router = useRouter();
  const qc = useQueryClient();
  const { org, orgId, hasNoOrg, isLoading: orgLoading } = useActiveOrg();
  const [cycle, setCycle] = useState<"monthly" | "yearly">("monthly");
  const [pendingPlanId, setPendingPlanId] = useState<string | null>(null);

  const plansQuery = useQuery({ queryKey: ["public-plans"], queryFn: getPublicPlans });

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
      toast.success("Paket berhasil diperbarui", {
        description: "Kapasitas event aktifmu kini bertambah.",
        action: { label: "Buat event", onClick: () => router.push("/organizer/events/new") },
      });
      router.push("/organizer/subscription");
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal memproses upgrade.").message),
    onSettled: () => setPendingPlanId(null),
  });

  if (orgLoading) {
    return (
      <div>
        <PageHeader title="Upgrade paket" backHref="/organizer/subscription" backLabel="Langganan" />
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {[0, 1, 2, 3].map((i) => (
            <Skeleton key={i} className="h-56 w-full rounded-xl" />
          ))}
        </div>
      </div>
    );
  }

  if (hasNoOrg) {
    return (
      <div>
        <PageHeader title="Upgrade paket" backHref="/organizer/subscription" backLabel="Langganan" />
        <EmptyState
          icon={Building2}
          title="Belum punya organisasi"
          description="Buat organisasi terlebih dahulu sebelum memilih paket."
          action={
            <Button asChild>
              <Link href="/onboarding">Buat organisasi</Link>
            </Button>
          }
        />
      </div>
    );
  }

  const currentPlanId = org?.plan_id ?? org?.plan?.id ?? null;
  const limit = getActiveEventLimit(org);

  return (
    <div>
      <PageHeader
        title="Upgrade paket"
        description={
          org?.plan
            ? `Paket saat ini: ${org.plan.name}${
                limit !== null ? ` — maks ${limit} event aktif` : " — event aktif tanpa batas"
              }.`
            : "Pilih paket yang sesuai dengan skala turnamenmu."
        }
        backHref="/organizer/subscription"
        backLabel="Langganan"
      />

      <div className="mb-5 inline-flex items-center gap-1 rounded-full border border-border bg-[var(--surface)] p-1 text-sm font-semibold">
        {(["monthly", "yearly"] as const).map((c) => (
          <button
            key={c}
            onClick={() => setCycle(c)}
            className={cn(
              "rounded-full px-4 py-1.5 transition-colors",
              cycle === c
                ? "bg-[var(--brand-600)] text-white"
                : "text-muted-foreground hover:text-foreground"
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
          const isCurrent = plan.id === currentPlanId;
          return (
            <Card
              key={plan.id}
              className={cn(
                "relative flex flex-col p-5",
                isCurrent
                  ? "ring-1 ring-[var(--brand-600)]"
                  : featured && "ring-1 ring-[color-mix(in_srgb,var(--brand-600)_50%,transparent)]"
              )}
            >
              {isCurrent && (
                <span className="absolute -top-2.5 left-5 rounded-full bg-[var(--brand-600)] px-2.5 py-0.5 text-[11px] font-bold text-white">
                  Paket aktif
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

              <ul className="mt-4 flex-1 space-y-1.5 text-xs">
                {plan.feature_details?.map((feature) => (
                  <li
                    key={feature.key}
                    title={feature.description ?? undefined}
                    className={cn(
                      "flex items-start gap-1.5",
                      !feature.included && "text-muted-foreground/60"
                    )}
                  >
                    {feature.included ? (
                      <Check className="mt-px h-3.5 w-3.5 shrink-0" style={{ color }} />
                    ) : (
                      <X className="mt-px h-3.5 w-3.5 shrink-0" />
                    )}
                    <span className={cn(!feature.included && "line-through")}>
                      {formatPlanFeature(feature)}
                    </span>
                  </li>
                ))}
              </ul>

              <Button
                className="mt-4"
                variant={isCurrent ? "outline" : featured ? "default" : "outline"}
                disabled={isCurrent || checkout.isPending}
                onClick={() => {
                  setPendingPlanId(plan.id);
                  checkout.mutate(plan);
                }}
              >
                {isCurrent ? (
                  <>
                    <Check className="h-4 w-4" />
                    Paket aktif
                  </>
                ) : pendingPlanId === plan.id ? (
                  "Memproses…"
                ) : (
                  "Pilih paket"
                )}
              </Button>
            </Card>
          );
        })}
      </div>
    </div>
  );
}
