"use client";

import { useState } from "react";
import Link from "next/link";
import { CheckIcon, CrossIcon } from "./icons";

type Feature = { label: string; off?: boolean };

type Plan = {
  name: string;
  swatch: string;
  desc: string;
  priceMonthly: string;
  priceYearly: string;
  per?: string;
  note: string;
  cta: string;
  href: string;
  featured?: boolean;
  tag?: string;
  delay?: string;
  features: Feature[];
};

const SALES_MAILTO =
  "mailto:sales@flo-event.id?subject=Tertarik%20paket%20Professional%20flo-event";

const PLANS: Plan[] = [
  {
    name: "Free",
    swatch: "var(--plan-free)",
    desc: "Untuk komunitas kecil yang baru mulai.",
    priceMonthly: "0",
    priceYearly: "0",
    note: "Gratis selamanya",
    cta: "Mulai Gratis",
    href: "/register",
    features: [
      { label: "1 event aktif" },
      { label: "8 tim per event" },
      { label: "Jadwal, klasemen & bracket" },
      { label: "Statistik basic" },
      { label: "Tiket QR & sertifikat", off: true },
    ],
  },
  {
    name: "Starter",
    swatch: "var(--plan-starter)",
    desc: "Untuk klub & kampus yang rutin gelar event.",
    priceMonthly: "149rb",
    priceYearly: "119rb",
    per: "/bln",
    note: "≈ Rp 1,43jt/tahun",
    cta: "Pilih Starter",
    href: "/register",
    delay: "60",
    features: [
      { label: "3 event aktif · 32 tim" },
      { label: "Payment gateway" },
      { label: "Tiket QR (500/event)" },
      { label: "Generator sertifikat" },
      { label: "Export Excel & PDF" },
    ],
  },
  {
    name: "Pro",
    swatch: "var(--plan-pro)",
    desc: "Untuk EO profesional & turnamen besar.",
    priceMonthly: "399rb",
    priceYearly: "319rb",
    per: "/bln",
    note: "≈ Rp 3,83jt/tahun",
    cta: "Pilih Pro",
    href: "/register",
    featured: true,
    tag: "Paling Populer",
    delay: "120",
    features: [
      { label: "10 event aktif · 128 tim" },
      { label: "Tiket QR (5.000/event)" },
      { label: "Kirim sertifikat via email" },
      { label: "Laporan turnamen full" },
      { label: "Custom subdomain · priority support" },
    ],
  },
  {
    name: "Professional",
    swatch: "var(--plan-professional)",
    desc: "Untuk federasi & turnamen skala nasional.",
    priceMonthly: "999rb",
    priceYearly: "799rb",
    per: "/bln",
    note: "≈ Rp 9,59jt/tahun",
    cta: "Hubungi Sales",
    href: SALES_MAILTO,
    delay: "180",
    features: [
      { label: "Event & tim unlimited" },
      { label: "Tiket unlimited · fee 1%" },
      { label: "Custom domain & white label" },
      { label: "API access" },
      { label: "Dedicated support" },
    ],
  },
];

export function Pricing() {
  const [yearly, setYearly] = useState(false);

  const toggle = () => {
    const next = !yearly;
    setYearly(next);
    document.body.setAttribute("data-billing", next ? "yearly" : "monthly");
  };

  return (
    <section
      className="section"
      id="harga"
      style={{ background: "var(--bg-alt)", borderBlock: "1px solid var(--border)" }}
    >
      <div className="container">
        <div className="section-head center reveal">
          <span className="eyebrow">Harga</span>
          <h2 className="section-title">Mulai gratis, upgrade saat turnamenmu membesar</h2>
          <p className="section-sub">
            Semua paket termasuk landing page event, registrasi tim, jadwal, klasemen, dan bracket. Tanpa biaya
            tersembunyi.
          </p>
          <div className="bill-switch">
            <span className="lbl lbl-m">Bulanan</span>
            <button
              className="switch"
              onClick={toggle}
              role="switch"
              aria-checked={yearly}
              aria-label="Tagihan bulanan atau tahunan"
            />
            <span className="lbl lbl-y">Tahunan</span>
            <span className="save">Hemat 20%</span>
          </div>
        </div>

        <div className="price-grid">
          {PLANS.map((p) => (
            <article key={p.name} className={`plan${p.featured ? " featured" : ""} reveal`} data-delay={p.delay}>
              {p.tag && <span className="plan-tag">{p.tag}</span>}
              <div className="plan-name">
                <span className="swatch" style={{ background: p.swatch }} /> {p.name}
              </div>
              <p className="plan-desc">{p.desc}</p>
              <div className="plan-price">
                <span className="cur">Rp</span>
                <span className="amt amt-m">{p.priceMonthly}</span>
                <span className="amt amt-y">{p.priceYearly}</span>
                {p.per && <span className="per">{p.per}</span>}
              </div>
              <p className="plan-note">{p.note}</p>
              {p.href.startsWith("/") ? (
                <Link href={p.href} className={`btn ${p.featured ? "btn-primary" : "btn-secondary"} btn-block`}>
                  {p.cta}
                </Link>
              ) : (
                <a href={p.href} className={`btn ${p.featured ? "btn-primary" : "btn-secondary"} btn-block`}>
                  {p.cta}
                </a>
              )}
              <ul className="plan-feats">
                {p.features.map((f) => (
                  <li key={f.label} className={f.off ? "off" : undefined}>
                    {f.off ? <CrossIcon /> : <CheckIcon />} {f.label}
                  </li>
                ))}
              </ul>
            </article>
          ))}
        </div>
        <p className="price-foot">
          Platform fee tiket: 3% (Starter) · 2% (Pro) · 1% (Professional). Diskon 20% untuk pembayaran tahunan.
        </p>
      </div>
    </section>
  );
}
