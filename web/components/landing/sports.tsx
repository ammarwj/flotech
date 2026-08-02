"use client";

import { useEffect, useRef } from "react";

import { observeReveals } from "@/components/landing/reveal-init";
import { useCatalog } from "@/lib/hooks/use-catalog";
import { participantLabel, participantModes } from "@/lib/scoring";

/**
 * The sports the platform supports — straight from the catalog, so a sport the
 * admin adds shows up on the marketing page too (no more "five sports" copy
 * drifting out of date).
 */
export function Sports() {
  const { sports, tournament_formats } = useCatalog();
  const sectionRef = useRef<HTMLElement>(null);

  /**
   * `.reveal` starts at `opacity: 0` and only clears once an IntersectionObserver
   * has seen it, and RevealInit only ever observes what is in the DOM when the
   * page mounts. Nothing here is: the catalog is fetched client-side and the
   * early return below keeps the whole section — heading included — out of that
   * first sweep, so without this the cards never become visible at all.
   *
   * The symptom looks intermittent, which is what hides it. `useCatalog` holds
   * its answer forever (`staleTime: Infinity`), so arriving from another page
   * renders the section in the first commit and RevealInit catches it; a cold
   * load resolves the request after the sweep and it stays blank.
   *
   * Observes the whole section rather than just the grid the way Pricing does —
   * Pricing renders its heading before the data lands, so RevealInit already
   * covers that half. Here nothing is covered.
   */
  useEffect(() => {
    if (sports.length === 0 || !sectionRef.current) return;
    return observeReveals(sectionRef.current);
  }, [sports.length]);

  if (sports.length === 0) return null;

  const formats = tournament_formats.map((f) => f.label).join(" · ");

  return (
    <section
      ref={sectionRef}
      className="section section-sm"
      id="cabang"
      style={{ background: "var(--bg-alt)", borderBlock: "1px solid var(--border)" }}
    >
      <div className="container">
        <div className="section-head center reveal">
          <span className="eyebrow">Cabang Olahraga</span>
          <h2 className="section-title">
            {sports.length} cabang, banyak format turnamen
          </h2>
          <p className="section-sub">
            Setiap cabang punya aturan skor, statistik, dan klasemen yang tepat sesuai karakter
            olahraganya. Semua bisa dijalankan sebagai {formats}.
          </p>
        </div>
        <div className="sports-grid">
          {sports.map((s, i) => (
            <article
              key={s.slug}
              className="sport reveal"
              data-delay={i === 0 ? undefined : String(i * 60)}
              style={{ ["--sc" as string]: s.color }}
            >
              <div className="sport-ic">{s.icon ?? "🏆"}</div>
              <h4>{s.name}</h4>
              {/* The entrant shapes this sport supports — the one line that
                  actually differs between cards. It used to print the format
                  list, which is identical for every sport, so nine cards
                  repeated the same sentence and said nothing. */}
              <p>{participantModes(s).map(participantLabel).join(" · ")}</p>
            </article>
          ))}
        </div>
      </div>
    </section>
  );
}
