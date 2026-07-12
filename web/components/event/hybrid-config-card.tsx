"use client";

import { ArrowDown, ArrowUp, Network } from "lucide-react";

import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { SectionHeader } from "@/components/event/section-header";
import {
  bracketSize,
  byeCount,
  hybridConfig,
  qualifierCount,
  totalTeams,
  KNOCKOUT_ROUND_SIZES,
  type HybridConfig,
} from "@/lib/hybrid";
import {
  DRAW_METHOD_LABELS,
  KNOCKOUT_ROUND_LABELS,
  TIEBREAKER_LABELS,
} from "@/lib/labels";
import type { BracketConfig, DrawMethod, KnockoutRound, Tiebreaker } from "@/types/api";

/** Round label for a bracket of `size` teams, e.g. 8 → "Perempat Final". */
function bracketRoundLabel(size: number): string {
  const round = (Object.keys(KNOCKOUT_ROUND_SIZES) as KnockoutRound[]).find(
    (k) => KNOCKOUT_ROUND_SIZES[k] === size
  );
  return round ? KNOCKOUT_ROUND_LABELS[round] : `${size} Besar`;
}

function Sub({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <section className="grid gap-3 border-t border-border pt-4 first:border-0 first:pt-0">
      <h3 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{title}</h3>
      {children}
    </section>
  );
}

function NumField({
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
 * Everything that defines a Group + Knockout event: the group structure, the
 * points, who qualifies, how the bracket starts, how the draw is made, and the
 * tiebreaker order. Writes straight into the event's `bracket_config`.
 */
export function HybridConfigCard({
  value,
  onChange,
}: {
  value?: BracketConfig | null;
  onChange: (config: BracketConfig) => void;
}) {
  const c = hybridConfig(value);

  const set = (patch: Partial<HybridConfig>) => onChange({ ...c, ...patch });

  const qualified = qualifierCount(c);
  const size = bracketSize(c);
  const byes = byeCount(c);
  const tooMany = qualified > size;

  const moveTiebreaker = (index: number, dir: -1 | 1) => {
    const next = [...c.tiebreakers];
    const to = index + dir;
    if (to < 0 || to >= next.length) return;
    [next[index], next[to]] = [next[to], next[index]];
    set({ tiebreakers: next as Tiebreaker[] });
  };

  return (
    <Card>
      <SectionHeader
        icon={Network}
        title="Konfigurasi Grup + Knockout"
        description="Struktur grup, aturan lolos, dan bracket knockout dibuat otomatis dari sini."
      />
      <CardContent className="grid gap-5">
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

        <Sub title="Poin klasemen">
          <div className="grid gap-4 sm:grid-cols-3">
            <NumField
              label="Poin menang"
              value={c.points.win}
              min={0}
              max={10}
              onChange={(win) => set({ points: { ...c.points, win } })}
            />
            <NumField
              label="Poin seri"
              value={c.points.draw}
              min={0}
              max={10}
              onChange={(draw) => set({ points: { ...c.points, draw } })}
            />
            <NumField
              label="Poin kalah"
              value={c.points.lose}
              min={0}
              max={10}
              onChange={(lose) => set({ points: { ...c.points, lose } })}
            />
          </div>
        </Sub>

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
                {(Object.keys(KNOCKOUT_ROUND_LABELS) as KnockoutRound[]).map((k) => (
                  <option key={k} value={k}>
                    {KNOCKOUT_ROUND_LABELS[k]}
                  </option>
                ))}
              </Select>
            </div>
            <div className="grid gap-1.5">
              <Label className="font-semibold">Metode undian grup</Label>
              <Select
                value={c.draw_method}
                onChange={(e) => set({ draw_method: e.target.value as DrawMethod })}
              >
                {(Object.keys(DRAW_METHOD_LABELS) as DrawMethod[]).map((k) => (
                  <option key={k} value={k}>
                    {DRAW_METHOD_LABELS[k]}
                  </option>
                ))}
              </Select>
              <p className="text-xs text-muted-foreground">
                Undian bisa diulang kapan saja dari halaman Jadwal.
              </p>
            </div>
          </div>
        </Sub>

        <Sub title="Tie breaker">
          <p className="-mt-1 text-xs text-muted-foreground">
            Dipakai berurutan saat poin sama. Geser untuk mengubah prioritas.
          </p>
          <ol className="grid gap-2">
            {c.tiebreakers.map((t, i) => (
              <li
                key={t}
                className="flex items-center gap-3 rounded-md border border-border bg-[var(--bg-soft)] px-3 py-2"
              >
                <span className="grid h-6 w-6 shrink-0 place-items-center rounded-md bg-card text-xs font-bold">
                  {i + 1}
                </span>
                <span className="flex-1 text-sm font-medium">{TIEBREAKER_LABELS[t]}</span>
                <button
                  type="button"
                  onClick={() => moveTiebreaker(i, -1)}
                  disabled={i === 0}
                  aria-label={`Naikkan ${TIEBREAKER_LABELS[t]}`}
                  className="rounded p-1 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground disabled:opacity-30"
                >
                  <ArrowUp className="h-4 w-4" />
                </button>
                <button
                  type="button"
                  onClick={() => moveTiebreaker(i, 1)}
                  disabled={i === c.tiebreakers.length - 1}
                  aria-label={`Turunkan ${TIEBREAKER_LABELS[t]}`}
                  className="rounded p-1 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground disabled:opacity-30"
                >
                  <ArrowDown className="h-4 w-4" />
                </button>
              </li>
            ))}
          </ol>
        </Sub>

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
            {qualified} tim lolos → bracket {bracketRoundLabel(size)}
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
