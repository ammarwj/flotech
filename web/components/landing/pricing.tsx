"use client";

import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import { useQuery } from "@tanstack/react-query";

import { usePlanCtaHref } from "@/components/auth/public-auth-actions";
import { observeReveals } from "@/components/landing/reveal-init";
import { Skeleton } from "@/components/ui/skeleton";
import { getPublicPlans } from "@/lib/api/plans";
import { rupiahCompact } from "@/lib/labels";
import {
  formatPlanFeature,
  getMaxYearlyDiscount,
  getMonthlyEquivalent,
  getPlanColor,
  getPlanFeatureValue,
} from "@/lib/plan";
import type { Plan } from "@/types/api";
import { CheckIcon, CrossIcon } from "./icons";

const SALES_MAILTO =
  "mailto:sales@flo-event.id?subject=Tertarik%20paket%20Professional%20flo-event";

/**
 * The bits of a card the plan catalogue has no opinion about: the top plan is a
 * sales conversation rather than a self-serve checkout, and `pro` is the one we
 * push. Everything else on the card — price, description, features — is API data.
 */
function ctaFor(plan: Plan): { label: string; href: string } {
  if (plan.slug === "professional") return { label: "Hubungi Sales", href: SALES_MAILTO };
  return { label: `Pilih ${plan.name}`, href: "/register" };
}

/** Shown only in yearly mode (the monthly note is hidden by CSS). */
function yearlyNote(plan: Plan): string {
  if (plan.price_monthly === 0) return "Gratis selamanya";
  return `≈ Rp ${rupiahCompact(plan.price_yearly)}/tahun`;
}

/** Ticket platform fee per plan, e.g. "3% (Starter) · 2% (Pro)". */
function feeFootnote(plans: Plan[]): string | null {
  const fees = plans.flatMap((plan) => {
    const fee = getPlanFeatureValue(plan, "ticket_fee_percent");
    return fee ? [`${fee}% (${plan.name})`] : [];
  });

  return fees.length > 0 ? `Platform fee tiket: ${fees.join(" · ")}.` : null;
}

export function Pricing() {
  const [yearly, setYearly] = useState(false);
  const gridRef = useRef<HTMLDivElement>(null);

  const plansQuery = useQuery({ queryKey: ["public-plans"], queryFn: getPublicPlans });
  const plans = plansQuery.data;

  // The cards don't exist when RevealInit sweeps the page, so they'd never be
  // revealed. Observe them here once they've rendered.
  useEffect(() => {
    if (!plans || !gridRef.current) return;
    return observeReveals(gridRef.current);
  }, [plans]);

  // The CSS reads the cycle off the body, but the body outlives this section:
  // without the cleanup, leaving the page and coming back would leave a stale
  // "yearly" attribute contradicting the freshly-reset switch.
  useEffect(() => {
    document.body.setAttribute("data-billing", yearly ? "yearly" : "monthly");
    return () => document.body.setAttribute("data-billing", "monthly");
  }, [yearly]);

  const discount = getMaxYearlyDiscount(plans);

  return (
    <section
      className="section"
      id="harga"
      style={{ background: "var(--bg-alt)", borderBlock: "1px solid var(--border)" }}
    >
      <div className="container">
        <div className="section-head center reveal">
          <span className="eyebrow">Harga</span>
          <h2 className="section-title">Mulai kecil, upgrade saat turnamenmu membesar</h2>
          <p className="section-sub">
            Semua paket termasuk landing page event, registrasi tim, jadwal, klasemen, dan bracket. Tanpa biaya
            tersembunyi.
          </p>
          <div className="bill-switch">
            <span className="lbl lbl-m">Bulanan</span>
            <button
              className="switch"
              onClick={() => setYearly((v) => !v)}
              role="switch"
              aria-checked={yearly}
              aria-label="Tagihan bulanan atau tahunan"
            />
            <span className="lbl lbl-y">Tahunan</span>
            {discount > 0 && <span className="save">Hemat {discount}%</span>}
          </div>
        </div>

        <div className="price-grid" ref={gridRef}>
          {plansQuery.isPending
            ? [0, 1, 2, 3].map((i) => (
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
            {discount > 0 && ` Diskon ${discount}% untuk pembayaran tahunan.`}
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
        {/* Yearly is billed as one sum, but the card compares plans per month. */}
        <span className="amt amt-m">{rupiahCompact(plan.price_monthly)}</span>
        <span className="amt amt-y">{rupiahCompact(getMonthlyEquivalent(plan))}</span>
        {plan.price_monthly > 0 && <span className="per">/bln</span>}
      </div>
      <p className="plan-note">{yearlyNote(plan)}</p>
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
 * form. The Professional card points at a mailto and is left alone.
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
