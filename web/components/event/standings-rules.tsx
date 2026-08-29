"use client";

import { ArrowDown, ArrowUp, ListOrdered } from "lucide-react";

import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { SectionHeader } from "@/components/event/section-header";
import { hybridConfig, type HybridConfig } from "@/lib/hybrid";
import { useCatalog } from "@/lib/hooks/use-catalog";
import type { BracketConfig, StandingsContext, Tiebreaker } from "@/types/api";

/**
 * The two settings a table is ranked by — the points and the tiebreaker order —
 * and the field furniture the format cards share.
 *
 * They live here rather than inside HybridConfigCard because both a group stage
 * and a standalone league are ranked by StandingService reading the same
 * `bracket_config`: a second copy of these sections would be a second set of
 * labels and defaults to keep in step with it. Same reason MatchCardHeader
 * exists.
 */
export function Sub({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <section className="grid gap-3 border-t border-border pt-4 first:border-0 first:pt-0">
      <h3 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{title}</h3>
      {children}
    </section>
  );
}

export function NumField({
  label,
  hint,
  value,
  min,
  max,
  disabled,
  onChange,
}: {
  label: string;
  hint?: string;
  value: number;
  min: number;
  max: number;
  disabled?: boolean;
  onChange: (n: number) => void;
}) {
  return (
    <div className="grid gap-1.5">
      <Label className="font-semibold">{label}</Label>
      <Input
        type="number"
        min={min}
        max={max}
        value={value}
        disabled={disabled}
        onChange={(e) => onChange(Math.max(min, Math.min(max, Number(e.target.value) || min)))}
      />
      {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
    </div>
  );
}

/**
 * Points per result. A set-based category has no draw to award a point for, so
 * the middle field is not offered there at all.
 */
export function PointsSection({
  config,
  context,
  onChange,
}: {
  config: HybridConfig;
  context: StandingsContext;
  onChange: (points: HybridConfig["points"]) => void;
}) {
  const hasDraws = context !== "set";

  return (
    <Sub title="Poin klasemen">
      <div className={`grid gap-4 ${hasDraws ? "sm:grid-cols-3" : "sm:grid-cols-2"}`}>
        <NumField
          label="Poin menang"
          value={config.points.win}
          min={0}
          max={10}
          onChange={(win) => onChange({ ...config.points, win })}
        />
        {hasDraws && (
          <NumField
            label="Poin seri"
            value={config.points.draw}
            min={0}
            max={10}
            onChange={(draw) => onChange({ ...config.points, draw })}
          />
        )}
        <NumField
          label="Poin kalah"
          value={config.points.lose}
          min={0}
          max={10}
          onChange={(lose) => onChange({ ...config.points, lose })}
        />
      </div>
    </Sub>
  );
}

/** The tiebreaker order, applied top down whenever teams are level on points. */
export function TiebreakerSection({
  config,
  onChange,
}: {
  config: HybridConfig;
  onChange: (tiebreakers: Tiebreaker[]) => void;
}) {
  const { tiebreakerLabel } = useCatalog();

  const move = (index: number, dir: -1 | 1) => {
    const next = [...config.tiebreakers];
    const to = index + dir;
    if (to < 0 || to >= next.length) return;
    [next[index], next[to]] = [next[to], next[index]];
    onChange(next as Tiebreaker[]);
  };

  return (
    <Sub title="Tie breaker">
      <p className="-mt-1 text-xs text-muted-foreground">
        Dipakai berurutan saat poin sama. Geser untuk mengubah prioritas.
      </p>
      <ol className="grid gap-2">
        {config.tiebreakers.map((t, i) => (
          <li
            key={t}
            className="flex flex-wrap items-center gap-2 rounded-md border border-border bg-[var(--bg-soft)] px-3 py-2 sm:gap-3"
          >
            <span className="grid h-6 w-6 shrink-0 place-items-center rounded-md bg-card text-xs font-bold">
              {i + 1}
            </span>
            {/* min-w-0: a flex item defaults to min-width:auto and refuses to
                shrink below its content, which would push the reorder buttons
                out of the box on a phone. */}
            <span className="min-w-0 flex-1 text-sm font-medium">{tiebreakerLabel(t)}</span>
            <button
              type="button"
              onClick={() => move(i, -1)}
              disabled={i === 0}
              aria-label={`Naikkan ${tiebreakerLabel(t)}`}
              className="rounded p-1 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground disabled:opacity-30"
            >
              <ArrowUp className="h-4 w-4" />
            </button>
            <button
              type="button"
              onClick={() => move(i, 1)}
              disabled={i === config.tiebreakers.length - 1}
              aria-label={`Turunkan ${tiebreakerLabel(t)}`}
              className="rounded p-1 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground disabled:opacity-30"
            >
              <ArrowDown className="h-4 w-4" />
            </button>
          </li>
        ))}
      </ol>
    </Sub>
  );
}

/**
 * The standings settings of a standalone league. Its table is ranked by the
 * same config a group stage is, so the organizer gets the same two sections —
 * minus everything a league has no use for (groups, qualification, bracket).
 *
 * Writes a *patch*, never the whole config. hybridConfig() fills in every
 * DEFAULT_HYBRID key, and handing that back would store a group structure on a
 * category that has no groups — and overwrite whatever the format preset
 * ("Liga 2 Putaran" = 2 legs, see Catalog::formatDefaults) seeded. So the
 * defaults are read from it and only the edited key is sent.
 */
export function LeagueConfigCard({
  value,
  onChange,
  context = "goal",
}: {
  value?: BracketConfig | null;
  onChange: (config: BracketConfig) => void;
  /** The standings shape this category will be ranked in. */
  context?: StandingsContext;
}) {
  const catalog = useCatalog();
  const c = hybridConfig(
    value,
    catalog.tiebreakersFor(context).map((t) => t.key),
    context,
  );

  const patch = (fields: Partial<BracketConfig>) => onChange({ ...(value ?? {}), ...fields });

  return (
    <Card>
      <SectionHeader
        icon={ListOrdered}
        title="Konfigurasi Klasemen"
        description="Poin dan urutan tie breaker yang dipakai tabel liga ini."
      />
      <CardContent className="grid gap-5 p-4 pt-0 sm:p-6 sm:pt-0">
        <PointsSection config={c} context={context} onChange={(points) => patch({ points })} />
        <TiebreakerSection config={c} onChange={(tiebreakers) => patch({ tiebreakers })} />
      </CardContent>
    </Card>
  );
}
