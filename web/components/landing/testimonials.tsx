"use client";

import { useEffect, useRef } from "react";
import { useQuery } from "@tanstack/react-query";

import { observeReveals } from "@/components/landing/reveal-init";
import { Skeleton } from "@/components/ui/skeleton";
import { getPublicTestimonials } from "@/lib/api/landing";
import { avatarGradient } from "@/lib/landing";
import { StarIcon } from "./icons";

export function Testimonials() {
  const colRef = useRef<HTMLDivElement>(null);

  const query = useQuery({ queryKey: ["public-testimonials"], queryFn: getPublicTestimonials });
  const testimonials = query.data;

  // The cards don't exist when RevealInit sweeps the page, so they'd never be
  // revealed. Observe them here once they've rendered.
  useEffect(() => {
    if (!testimonials || !colRef.current) return;
    return observeReveals(colRef.current);
  }, [testimonials]);

  // Nothing to say beats a heading over an empty grid.
  if (query.isError || testimonials?.length === 0) return null;

  return (
    <section className="section">
      <div className="container">
        <div className="section-head center reveal">
          <span className="eyebrow">Kata Mereka</span>
          <h2 className="section-title">Penyelenggara di seluruh Indonesia</h2>
        </div>
        <div className="tcol" ref={colRef}>
          {query.isPending &&
            Array.from({ length: 3 }).map((_, i) => (
              <div key={i} className="tcard" aria-hidden>
                <Skeleton className="h-full min-h-[180px] w-full" />
              </div>
            ))}
          {testimonials?.map((t, i) => (
            <div key={t.id} className="tcard reveal" data-delay={String((i % 3) * 80)}>
              <div className="tstars">
                {Array.from({ length: t.rating }).map((_, star) => (
                  <StarIcon key={star} />
                ))}
              </div>
              <p>&ldquo;{t.quote}&rdquo;</p>
              <div className="who">
                <span className="av" style={{ background: avatarGradient(t.avatar_preset) }}>
                  {t.initials}
                </span>
                <div>
                  <b>{t.name}</b>
                  <small>{t.role}</small>
                </div>
              </div>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}
