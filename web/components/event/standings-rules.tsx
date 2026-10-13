"use client";

import * as React from "react";

import { ArrowDown, ArrowUp, ListOrdered } from "lucide-react";

import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
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

/**
 * A number input that is allowed to be *empty while being typed into*.
 *
 * Clamping to `min` on every keystroke — which is what a plain controlled
 * `Number(e.target.value) || min` does — makes the field impossible to clear:
 * deleting the last digit puts `min` straight back, and the next digit lands
 * *after* it, so clearing "1" to type "4" yields "14". On a laptop the habit of
 * select-all-then-type hides it; on a phone the caret lands at the end and
 * there is no way out of the field at all.
 *
 * So the draft is local and may be empty, and the clamp happens on blur — the
 * one moment the value has stopped changing. The parent still only ever sees a
 * number inside [min, max]. `value` is echoed back into the draft whenever it
 * changes from outside (clamp on blur, a preset, a reset), so the two cannot
 * drift.
 *
 * Leaving the field empty restores `anchor` — what it held when focus arrived —
 * and *not* `min`, which is the same bug one layer along: deleting "8" would
 * leave "1" behind, and the next digit lands after it. The anchor is needed
 * because shortening "12" to "1" is indistinguishable from typing "1", so the
 * live value has already followed the deletion down by the time the field goes
 * empty. On a laptop select-all-then-type hides both; on a phone the keyboard
 * blurs the field on every cleared digit, so this is the whole experience.
 */
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
  const [draft, setDraft] = React.useState(String(value));
  const [seen, setSeen] = React.useState(value);
  /** What the field held when focus arrived; an emptied field falls back here. */
  const anchor = React.useRef(value);

  if (seen !== value) {
    setSeen(value);
    setDraft(String(value));
  }

  const commit = (raw: string) => {
    const n = Number(raw);
    const next =
      raw.trim() === "" || Number.isNaN(n)
        ? Math.max(min, Math.min(max, anchor.current))
        : Math.max(min, Math.min(max, n));
    setDraft(String(next));
    setSeen(next);
    if (next !== value) onChange(next);
  };

  return (
    <div className="grid gap-1.5">
      <Label className="font-semibold">{label}</Label>
      <Input
        type="number"
        inputMode="numeric"
        min={min}
        max={max}
        value={draft}
        disabled={disabled}
        onFocus={(e) => {
          anchor.current = value;
          // The caret landing at the end is what turns "clear then type 8" into
          // "18" on a phone, where there is no select-all habit to hide it.
          e.target.select();
        }}
        onChange={(e) => {
          const raw = e.target.value;
          setDraft(raw);
          // An empty or half-typed field has no number to report; the parent
          // keeps the last good one until blur.
          const n = Number(raw);
          if (raw.trim() === "" || Number.isNaN(n)) return;
          const next = Math.max(min, Math.min(max, n));
          if (next === n && next !== value) {
            setSeen(next);
            onChange(next);
          }
        }}
        onBlur={(e) => commit(e.target.value)}
      />
      {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
    </div>
  );
}

/**
 * How many times every pairing is played: once, or home & away.
 *
 * The two controls are one setting read twice, which is why they are written
 * together — `home_away` is what the copy and the schedule *mean*, `legs` is
 * what ScheduleService::legRounds() counts, and either one set without the
 * other leaves a config that says two opposite things. HybridConfig::fromArray()
 * derives the missing half on read, but only the half it can: a stored
 * `home_away: true` beside `legs: 1` is kept as a single round.
 *
 * Shared by the group stage and the standalone league for the same reason the
 * points and tiebreakers are: the round-robin generator behind both is the same
 * method reading the same key, so a second copy of these labels is a second set
 * to keep in step.
 *
 * Returned as a fragment, not a card: each format card already has a section to
 * put it in ("Struktur grup" for hybrid, "Putaran" for a league), and the grid
 * around it belongs to that card.
 */
export function LegsFields({
  config,
  onChange,
}: {
  config: HybridConfig;
  onChange: (fields: { home_away: boolean; legs: number }) => void;
}) {
  return (
    <>
      <label className="flex cursor-pointer items-start gap-2 text-sm">
        <input
          type="checkbox"
          className="mt-0.5 h-4 w-4 accent-[var(--brand-600)]"
          checked={config.home_away}
          onChange={(e) =>
            onChange({ home_away: e.target.checked, legs: e.target.checked ? 2 : 1 })
          }
        />
        <span>
          <span className="font-medium">Home &amp; Away</span>
          <span className="block text-xs text-muted-foreground">
            Setiap tim bertemu dua kali, kandang dan tandang.
          </span>
        </span>
      </label>
      <div className="grid gap-1.5">
        <Label className="font-semibold">Jumlah leg</Label>
        <Select
          value={String(config.legs)}
          onChange={(e) =>
            onChange({ legs: Number(e.target.value), home_away: e.target.value === "2" })
          }
        >
          <option value="1">Single Leg</option>
          <option value="2">Double Leg</option>
        </Select>
      </div>
    </>
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
 * same config a group stage is, so the organizer gets the same sections —
 * minus everything a league has no use for (groups, qualification, bracket).
 *
 * Home & away is here for the same reason: generateRoundRobin() and
 * generateGroupStage() both expand their pairings through legRounds(), so the
 * key has always worked for a league — it just had no control, and the only way
 * to a two-round league was a catalog preset ("Liga 2 Putaran").
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
        title="Konfigurasi Liga"
        description="Jumlah putaran, poin, dan urutan tie breaker yang dipakai tabel liga ini."
      />
      <CardContent className="grid gap-5 p-4 pt-0 sm:p-6 sm:pt-0">
        <Sub title="Putaran">
          <div className="grid gap-4 sm:grid-cols-2">
            <LegsFields config={c} onChange={patch} />
          </div>
          <p className="-mt-1 text-xs text-muted-foreground">
            Mengubahnya baru berlaku saat jadwal dibuat ulang dari halaman Jadwal.
          </p>
        </Sub>

        <PointsSection config={c} context={context} onChange={(points) => patch({ points })} />
        <TiebreakerSection config={c} onChange={(tiebreakers) => patch({ tiebreakers })} />
      </CardContent>
    </Card>
  );
}
