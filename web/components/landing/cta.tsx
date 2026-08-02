"use client";

import Link from "next/link";
import { useQuery } from "@tanstack/react-query";

import { usePublicCta } from "@/components/auth/public-auth-actions";
import { getPublicPlans } from "@/lib/api/plans";
import { rupiah } from "@/lib/labels";

export function Cta() {
  const cta = usePublicCta();

  // The price was hardcoded as "Rp 49.000/bulan" — the retired Basic plan, and a
  // monthly subscription this product no longer sells. Read it instead, on the
  // same `["public-plans"]` key the Pricing section above already fetched, so
  // this costs no request and can never drift from the catalogue again.
  const { data: plans } = useQuery({ queryKey: ["public-plans"], queryFn: getPublicPlans });
  const cheapest = plans?.length ? Math.min(...plans.map((p) => p.price)) : null;

  return (
    <section className="section cta-band">
      <div className="container">
        <div className="cta-card reveal">
          <h2>Siap menggelar turnamenmu?</h2>
          <p>
            Bergabung dengan 1.200+ penyelenggara yang sudah meninggalkan spreadsheet.{" "}
            {cheapest !== null && <>Mulai dari {rupiah(cheapest)} per event — </>}
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
