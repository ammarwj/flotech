"use client";

import { useCatalog } from "@/lib/hooks/use-catalog";

/**
 * The sports the platform supports — straight from the catalog, so a sport the
 * admin adds shows up on the marketing page too (no more "five sports" copy
 * drifting out of date).
 */
export function Sports() {
  const { sports, tournament_formats } = useCatalog();

  if (sports.length === 0) return null;

  const formats = tournament_formats.map((f) => f.label).join(" · ");

  return (
    <section
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
            Setiap cabang punya aturan skor, statistik, dan klasemen yang tepat sesuai karakter olahraganya.
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
              <p>{formats}</p>
            </article>
          ))}
        </div>
      </div>
    </section>
  );
}
