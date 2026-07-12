"use client";

import { useEffect, useState } from "react";
import { X } from "lucide-react";

import { useCatalog } from "@/lib/hooks/use-catalog";
import type { EventPhoto, EventSponsor } from "@/types/api";

/**
 * Logo size by rank: the first tier the admin configured (typically the host)
 * gets the biggest badge, the rest step down.
 */
const TIER_HEIGHTS = ["h-16", "h-14", "h-11", "h-11"];

/** Partner logos, grouped by tier, in the order the admin arranged them. */
export function SponsorStrip({ sponsors }: { sponsors: EventSponsor[] }) {
  const { sponsor_tiers } = useCatalog();

  if (sponsors.length === 0) return null;

  return (
    <>
      <div className="esection-title" style={{ marginTop: 40 }}>
        <h2 className="section-title" style={{ margin: 0 }}>
          Sponsor &amp; Partner
        </h2>
      </div>

      <div className="grid gap-5">
        {sponsor_tiers.map((tier, i) => {
          const list = sponsors.filter((s) => s.tier === tier.key);
          if (list.length === 0) return null;

          const height = TIER_HEIGHTS[Math.min(i, TIER_HEIGHTS.length - 1)];

          return (
            <div key={tier.key}>
              <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                {tier.label}
              </p>
              <div className="flex flex-wrap items-center gap-3">
                {list.map((s) => {
                  const logo = (
                    // eslint-disable-next-line @next/next/no-img-element
                    <img
                      src={s.logo_url}
                      alt={s.name}
                      title={s.name}
                      className={`${height} w-auto max-w-[160px] object-contain`}
                    />
                  );

                  return (
                    <span
                      key={s.id}
                      className="grid place-items-center rounded-lg border border-border bg-[var(--surface)] px-4 py-3"
                    >
                      {s.website_url ? (
                        <a href={s.website_url} target="_blank" rel="noopener noreferrer sponsored">
                          {logo}
                        </a>
                      ) : (
                        logo
                      )}
                    </span>
                  );
                })}
              </div>
            </div>
          );
        })}
      </div>
    </>
  );
}

/** Photo albums, with a click-to-enlarge viewer. */
export function PhotoGallery({ photos }: { photos: EventPhoto[] }) {
  const [active, setActive] = useState<EventPhoto | null>(null);

  useEffect(() => {
    if (!active) return;
    const onKey = (e: KeyboardEvent) => e.key === "Escape" && setActive(null);
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [active]);

  if (photos.length === 0) return null;

  // Albums in name order; the unnamed "general" album goes last.
  const albums = new Map<string, EventPhoto[]>();
  for (const p of photos) {
    const key = p.album ?? "";
    albums.set(key, [...(albums.get(key) ?? []), p]);
  }
  const names = [...albums.keys()].sort((a, b) =>
    a === "" ? 1 : b === "" ? -1 : a.localeCompare(b)
  );

  return (
    <>
      <div className="esection-title" style={{ marginTop: 40 }}>
        <h2 className="section-title" style={{ margin: 0 }}>
          Galeri
        </h2>
        <span className="pill">{photos.length} foto</span>
      </div>

      <div className="grid gap-6">
        {names.map((name) => (
          <div key={name || "umum"}>
            {names.length > 1 && (
              <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                {name || "Galeri umum"}
              </p>
            )}
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4">
              {albums.get(name)!.map((p) => (
                <button
                  key={p.id}
                  onClick={() => setActive(p)}
                  className="aspect-square overflow-hidden rounded-lg border border-border"
                  aria-label={p.caption ?? "Perbesar foto"}
                >
                  {/* eslint-disable-next-line @next/next/no-img-element */}
                  <img
                    src={p.photo_url}
                    alt={p.caption ?? ""}
                    loading="lazy"
                    className="h-full w-full object-cover transition-transform hover:scale-105"
                  />
                </button>
              ))}
            </div>
          </div>
        ))}
      </div>

      {active && (
        <div
          className="fixed inset-0 z-50 grid place-items-center bg-black/80 p-4"
          onClick={() => setActive(null)}
          role="dialog"
          aria-modal="true"
        >
          <button
            onClick={() => setActive(null)}
            aria-label="Tutup"
            className="absolute right-4 top-4 grid h-9 w-9 place-items-center rounded-md bg-white/10 text-white hover:bg-white/20"
          >
            <X className="h-5 w-5" />
          </button>
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img
            src={active.photo_url}
            alt={active.caption ?? ""}
            className="max-h-[85vh] max-w-full rounded-lg object-contain"
            onClick={(e) => e.stopPropagation()}
          />
          {active.caption && (
            <p className="mt-3 text-center text-sm text-white/80">{active.caption}</p>
          )}
        </div>
      )}
    </>
  );
}
