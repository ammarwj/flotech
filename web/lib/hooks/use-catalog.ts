"use client";

import { useQuery } from "@tanstack/react-query";

import { getCatalog } from "@/lib/api/catalog";
import type { Catalog, CatalogOption, SportDef } from "@/types/api";

const EMPTY: Catalog = {
  sports: [],
  tournament_formats: [],
  tiebreakers: [],
  draw_methods: [],
  knockout_rounds: [],
  sponsor_tiers: [],
};

const label = (options: CatalogOption[], key: string | null | undefined) =>
  options.find((o) => o.key === key)?.label ?? key ?? "";

/**
 * The admin-managed vocabulary — sports, formats, tiebreakers, draw methods,
 * knockout rounds, sponsor tiers — with helpers to turn a stored key into
 * something a person can read.
 *
 * It only changes when a super admin edits it, so it's fetched once and kept.
 */
export function useCatalog() {
  const query = useQuery({
    queryKey: ["catalog"],
    queryFn: getCatalog,
    staleTime: Infinity,
    gcTime: Infinity,
  });

  const catalog = query.data ?? EMPTY;

  const sport = (slug: string | null | undefined): SportDef | undefined =>
    catalog.sports.find((s) => s.slug === slug);

  return {
    ...catalog,
    isLoading: query.isLoading,

    sport,
    sportLabel: (slug: string | null | undefined) => sport(slug)?.name ?? slug ?? "",
    sportColor: (slug: string | null | undefined) => sport(slug)?.color ?? "var(--brand-600)",
    sportDuration: (slug: string | null | undefined) => sport(slug)?.default_match_minutes ?? 60,

    formatLabel: (key: string | null | undefined) => label(catalog.tournament_formats, key),
    tiebreakerLabel: (key: string | null | undefined) => label(catalog.tiebreakers, key),
    drawMethodLabel: (key: string | null | undefined) => label(catalog.draw_methods, key),
    roundLabel: (key: string | null | undefined) => label(catalog.knockout_rounds, key),
    sponsorTierLabel: (key: string | null | undefined) => label(catalog.sponsor_tiers, key),

    /** Teams held by a knockout entry round (round_of_16 → 16). */
    roundSize: (key: string | null | undefined) =>
      Number(catalog.knockout_rounds.find((r) => r.key === key)?.meta?.size ?? 0),

    /** Round name for a bracket of `size` teams — e.g. 8 → "Perempat Final". */
    roundLabelForSize: (size: number) =>
      catalog.knockout_rounds.find((r) => Number(r.meta?.size) === size)?.label ?? `${size} Besar`,
  };
}

/** True when the sport is scored per set (volleyball, racket sports). */
export function isSetBasedSport(sport: SportDef | null | undefined): boolean {
  return sport?.scoring === "set";
}
