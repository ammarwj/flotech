"use client";

import { useEffect, useRef, type CSSProperties } from "react";
import Link from "next/link";
import { useQuery } from "@tanstack/react-query";

import { usePlanCtaHref } from "@/components/auth/public-auth-actions";
import { observeReveals } from "@/components/landing/reveal-init";
import { Skeleton } from "@/components/ui/skeleton";
import { getPublicPlans } from "@/lib/api/plans";
import { rupiahCompact } from "@/lib/labels";
import { formatPlanFeature, getPlanColor, getPlanFeatureValue } from "@/lib/plan";
import type { Plan } from "@/types/api";
import { CheckIcon, CrossIcon } from "./icons";

/**
 * The one bit of a card the plan catalogue has no opinion about: `pro` is the
 * one we push. Everything else — price, description, features — is API data.
 *
 * Every plan is self-serve now. Professional used to open a mailto to sales,
 * which made sense against a Rp 999.000/month subscription; at Rp 800.000 for a
 * single event it is a checkout, and a mailto on a priced card is a dead end.
 * `sales_email` still exists in site_settings for the footer.
 */
function ctaFor(plan: Plan): { label: string; href: string } {
  return { label: `Pilih ${plan.name}`, href: "/register" };
}

/** Platform fee per plan, e.g. "3% (Starter) · 2% (Pro)". */
function feeFootnote(plans: Plan[]): string | null {
  const fees = plans.flatMap((plan) => {
    const fee = getPlanFeatureValue(plan, "platform_fee_percent");
    return fee ? [`${fee}% (${plan.name})`] : [];
  });

  return fees.length > 0 ? `Platform fee tiket & pendaftaran: ${fees.join(" · ")}.` : null;
}

export function Pricing() {
  const gridRef = useRef<HTMLDivElement>(null);

  const plansQuery = useQuery({ queryKey: ["public-plans"], queryFn: getPublicPlans });
  const plans = plansQuery.data;

  // The cards don't exist when RevealInit sweeps the page, so they'd never be
  // revealed. Observe them here once they've rendered.
  useEffect(() => {
    if (!plans || !gridRef.current) return;
    return observeReveals(gridRef.current);
  }, [plans]);

  return (
    <section
      className="section"
      id="harga"
      style={{ background: "var(--bg-alt)", borderBlock: "1px solid var(--border)" }}
    >
      <div className="container">
        <div className="section-head center reveal">
          <span className="eyebrow">Harga</span>
          <h2 className="section-title">Bayar sekali per event, bukan langganan</h2>
          <p className="section-sub">
            Pilih paket saat kamu menggelar event, dan itu berlaku sampai eventnya selesai — mau
            seminggu atau lintas bulan. Semua paket termasuk landing page event, registrasi tim,
            jadwal, klasemen, dan bracket. Tanpa biaya tersembunyi.
          </p>
        </div>

        {/* Drives the row's column count and max width — see .price-grid. Three
            while loading, since the real count isn't known yet. */}
        <div
          className="price-grid"
          ref={gridRef}
          style={{ "--plan-count": plans?.length ?? 3 } as CSSProperties}
        >
          {plansQuery.isPending
            ? [0, 1, 2].map((i) => (
                <article key={i} className="plan" aria-hidden>
                  <Skeleton className="h-full min-h-[420px] w-full" />
                </article>
              ))
            : plans?.map((plan, i) => (
                <PlanCard key={plan.id} plan={plan} delay={i > 0 ? String(i * 60) : undefined} />
              ))}
        </div>

        {plansQuery.isError && (
          <p className="price-foot">Gagal memuat paket. Coba muat ulang halaman ini.</p>
        )}

        {plans && (
          <p className="price-foot">
            {feeFootnote(plans)}
            {" Kalau payment gateway sedang bermasalah, pembayaran otomatis dialihkan ke transfer manual ke rekeningmu — tanpa potongan fee."}
          </p>
        )}
      </div>
    </section>
  );
}

function PlanCard({ plan, delay }: { plan: Plan; delay?: string }) {
  const featured = plan.slug === "pro";
  const cta = ctaFor(plan);

  return (
    <article className={`plan${featured ? " featured" : ""} reveal`} data-delay={delay}>
      {featured && <span className="plan-tag">Paling Populer</span>}
      <div className="plan-name">
        <span className="swatch" style={{ background: getPlanColor(plan.slug) }} /> {plan.name}
      </div>
      <p className="plan-desc">{plan.description}</p>
      <div className="plan-price">
        <span className="cur">Rp</span>
        <span className="amt">{rupiahCompact(plan.price)}</span>
        {plan.price > 0 && <span className="per">/event</span>}
      </div>
      <p className="plan-note">Sekali bayar · 1 event</p>
      <PlanCta cta={cta} featured={featured} />
      <ul className="plan-feats">
        {plan.feature_details?.map((feature) => (
          <li key={feature.key} className={feature.included ? undefined : "off"}>
            {feature.included ? <CheckIcon /> : <CrossIcon />} {formatPlanFeature(feature)}
          </li>
        ))}
      </ul>
    </article>
  );
}

/**
 * A signed-in organizer picking a plan wants the checkout page, not the sign-up
 * form.
 */
function PlanCta({ cta, featured }: { cta: { label: string; href: string }; featured: boolean }) {
  const href = usePlanCtaHref(cta.href);
  const className = `btn ${featured ? "btn-primary" : "btn-secondary"} btn-block`;

  return href.startsWith("/") ? (
    <Link href={href} className={className}>
      {cta.label}
    </Link>
  ) : (
    <a href={href} className={className}>
      {cta.label}
    </a>
  );
}
