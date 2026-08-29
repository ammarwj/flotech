"use client";

import { Network } from "lucide-react";

import { Card, CardContent } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { SectionHeader } from "@/components/event/section-header";
import {
  NumField,
  PointsSection,
  Sub,
  TiebreakerSection,
} from "@/components/event/standings-rules";
import {
  bracketSize,
  byeCount,
  hybridConfig,
  qualifierCount,
  totalTeams,
  type HybridConfig,
} from "@/lib/hybrid";
import { useCatalog } from "@/lib/hooks/use-catalog";
import type {
  BracketConfig,
  DrawMethod,
  KnockoutRound,
  StandingsContext,
} from "@/types/api";

/**
 * Everything that defines a Group + Knockout event: the group structure, the
 * points, who qualifies, how the bracket starts, how the draw is made, and the
 * tiebreaker order. Writes straight into the event's `bracket_config`.
 *
 * The vocabulary follows the sport, never the other way round: `context` picks
 * which tiebreakers exist ("Selisih Game" for badminton, "Selisih Gol" for
 * football) and what the points default to.
 */
export function HybridConfigCard({
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
  const set = (patch: Partial<HybridConfig>) => onChange({ ...c, ...patch });

  const qualified = qualifierCount(c);
  const size = bracketSize(c, catalog.roundSize);
  const byes = byeCount(c, catalog.roundSize);
  const tooMany = qualified > size;

  return (
    <Card>
      <SectionHeader
        icon={Network}
        title="Konfigurasi Grup + Knockout"
        description="Struktur grup, aturan lolos, dan bracket knockout dibuat otomatis dari sini."
      />
      {/* This card sits three boxes deep (main → Card → CategoryEditor → here),
          and at 360px the stacked padding leaves under 200px for the fields.
          Tighter below sm buys ~28px of it back. */}
      <CardContent className="grid gap-5 p-4 pt-0 sm:p-6 sm:pt-0">
        <Sub title="Struktur grup">
          <div className="grid gap-4 sm:grid-cols-3">
            <NumField
              label="Jumlah grup"
              value={c.groups}
              min={1}
              max={32}
              onChange={(groups) => set({ groups })}
            />
            <NumField
              label="Tim per grup"
              value={c.teams_per_group}
              min={2}
              max={16}
              onChange={(teams_per_group) => set({ teams_per_group })}
            />
            <div className="grid gap-1.5">
              <Label className="font-semibold">Total tim</Label>
              <div className="flex h-10 items-center rounded-md border border-border bg-[var(--bg-soft)] px-3 text-sm font-semibold">
                {totalTeams(c)} tim
              </div>
              <p className="text-xs text-muted-foreground">Grup × tim per grup.</p>
            </div>
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <label className="flex cursor-pointer items-start gap-2 text-sm">
              <input
                type="checkbox"
                className="mt-0.5 h-4 w-4 accent-[var(--brand-600)]"
                checked={c.home_away}
                onChange={(e) =>
                  set({ home_away: e.target.checked, legs: e.target.checked ? 2 : 1 })
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
                value={String(c.legs)}
                onChange={(e) => set({ legs: Number(e.target.value), home_away: e.target.value === "2" })}
              >
                <option value="1">Single Leg</option>
                <option value="2">Double Leg</option>
              </Select>
            </div>
          </div>
        </Sub>

        <PointsSection config={c} context={context} onChange={(points) => set({ points })} />

        <Sub title="Aturan lolos">
          <div className="grid gap-4 sm:grid-cols-3">
            <div className="grid gap-1.5">
              <Label className="font-semibold">Lolos otomatis</Label>
              <Select
                value={String(c.qualification.top_per_group)}
                onChange={(e) =>
                  set({
                    qualification: { ...c.qualification, top_per_group: Number(e.target.value) },
                  })
                }
              >
                <option value="1">Juara grup</option>
                <option value="2">Juara + Runner-up</option>
                <option value="3">3 teratas tiap grup</option>
              </Select>
              <p className="text-xs text-muted-foreground">Peringkat teratas tiap grup.</p>
            </div>
            <NumField
              label="Best Runner-up"
              hint={
                c.qualification.top_per_group >= 2
                  ? "Runner-up sudah lolos otomatis."
                  : "Peringkat 2 terbaik lintas grup."
              }
              value={c.qualification.best_runners_up}
              min={0}
              max={32}
              disabled={c.qualification.top_per_group >= 2}
              onChange={(best_runners_up) =>
                set({ qualification: { ...c.qualification, best_runners_up } })
              }
            />
            <NumField
              label="Best Third Place"
              hint={
                c.qualification.top_per_group >= 3
                  ? "Peringkat 3 sudah lolos otomatis."
                  : "Peringkat 3 terbaik lintas grup."
              }
              value={c.qualification.best_thirds}
              min={0}
              max={32}
              disabled={c.qualification.top_per_group >= 3}
              onChange={(best_thirds) => set({ qualification: { ...c.qualification, best_thirds } })}
            />
          </div>
        </Sub>

        <Sub title="Bracket knockout">
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="grid gap-1.5">
              <Label className="font-semibold">Babak awal</Label>
              <Select
                value={c.knockout_start ?? ""}
                onChange={(e) =>
                  set({ knockout_start: (e.target.value || null) as KnockoutRound | null })
                }
              >
                <option value="">Otomatis dari jumlah tim lolos</option>
                {catalog.knockout_rounds.map((r) => (
                  <option key={r.key} value={r.key}>
                    {r.label}
                  </option>
                ))}
              </Select>
            </div>
            <label className="flex cursor-pointer items-start gap-2 self-end pb-2 text-sm">
              <input
                type="checkbox"
                className="mt-0.5 h-4 w-4 accent-[var(--brand-600)]"
                checked={c.third_place}
                onChange={(e) => set({ third_place: e.target.checked })}
              />
              <span>
                <span className="font-medium">Perebutan juara 3</span>
                <span className="block text-xs text-muted-foreground">
                  Dua tim yang kalah di semifinal bermain sekali lagi.
                </span>
              </span>
            </label>
            <div className="grid gap-1.5">
              <Label className="font-semibold">Metode undian grup</Label>
              <Select
                value={c.draw_method}
                onChange={(e) => set({ draw_method: e.target.value as DrawMethod })}
              >
                {catalog.draw_methods.map((m) => (
                  <option key={m.key} value={m.key}>
                    {m.label}
                  </option>
                ))}
              </Select>
              <p className="text-xs text-muted-foreground">
                Undian bisa diulang kapan saja dari halaman Jadwal.
              </p>
            </div>
          </div>
        </Sub>

        <TiebreakerSection config={c} onChange={(tiebreakers) => set({ tiebreakers })} />

        <div
          className="rounded-lg border px-4 py-3 text-sm"
          style={
            tooMany
              ? {
                  borderColor: "color-mix(in srgb, var(--warning) 40%, transparent)",
                  background: "color-mix(in srgb, var(--warning) 8%, transparent)",
                }
              : { borderColor: "var(--border)", background: "var(--bg-soft)" }
          }
        >
          <p className="font-semibold">
            {c.groups} grup × {c.teams_per_group} tim = {totalTeams(c)} tim
          </p>
          <p className="mt-1 text-muted-foreground">
            {qualified} tim lolos → bracket {catalog.roundLabelForSize(size)}
            {byes > 0 && ` · ${byes} BYE`}
          </p>
          {tooMany && (
            <p className="mt-1 font-medium text-[var(--warning)]">
              Babak awal terlalu kecil: {qualified} tim lolos tapi bracket hanya memuat {size}.
            </p>
          )}
        </div>
      </CardContent>
    </Card>
  );
}
