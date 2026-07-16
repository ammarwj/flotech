"use client";

import Link from "next/link";

import { usePublicCta } from "@/components/auth/public-auth-actions";

export function Cta() {
  const cta = usePublicCta();

  return (
    <section className="section cta-band">
      <div className="container">
        <div className="cta-card reveal">
          <h2>Siap menggelar turnamenmu?</h2>
          <p>
            Bergabung dengan 1.200+ penyelenggara yang sudah meninggalkan spreadsheet. Mulai dari Rp 49.000/bulan —
            setup turnamen pertamamu dalam 10 menit.
          </p>
          <div className="hero-cta">
            <Link href={cta.href} className="btn btn-primary btn-lg">
              {cta.href === "/register" ? "Mulai Sekarang" : cta.label}
            </Link>
            <Link href="/event" className="btn btn-secondary btn-lg">
              Jelajahi Event
            </Link>
          </div>
        </div>
      </div>
    </section>
  );
}
