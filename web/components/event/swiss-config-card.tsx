"use client";

import { Repeat } from "lucide-react";

import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { SectionHeader } from "@/components/event/section-header";
import { PointsSection, Sub, TiebreakerSection } from "@/components/event/standings-rules";
import { hybridConfig } from "@/lib/hybrid";
import { useCatalog } from "@/lib/hooks/use-catalog";
import type { BracketConfig, StandingsContext } from "@/types/api";

/** Mirrors HybridConfig::swissRoundCount() on the API. */
export function derivedSwissRounds(teams: number): number {
  return Math.max(3, Math.ceil(Math.log2(Math.max(2, teams))));
}

/**
 * Swiss settings: how many rounds, plus the two sections every table shares.
 *
 * The points and tiebreakers come from standings-rules so there is one set of
 * labels and defaults across the league, hybrid and Swiss cards — same reason
 * LeagueConfigCard reuses them.
 *
 * Nothing here switches rematch avoidance or bye fairness on and off: those are
 * rules of the format, not settings, and a knob for them would be a knob for
 * running Swiss wrongly.
 */
export function SwissConfigCard({
  value,
  onChange,
  context = "goal",
  teams = 0,
}: {
  value?: BracketConfig | null;
  onChange: (config: BracketConfig) => void;
  /** The standings shape this category will be ranked in. */
  context?: StandingsContext;
  /** Entrants approved so far — only used to show the derived round count. */
  teams?: number;
}) {
  const catalog = useCatalog();
  const c = hybridConfig(
    value,
    catalog.tiebreakersFor(context).map((t) => t.key),
    context,
  );

  // A patch, never the whole config: hybridConfig() fills in every
  // DEFAULT_HYBRID key, and handing that back would store a group structure on
  // a category that draws no groups. Same reason LeagueConfigCard does it.
  const patch = (fields: Partial<BracketConfig>) => onChange({ ...(value ?? {}), ...fields });

  const derived = derivedSwissRounds(teams);

  return (
    <Card>
      <SectionHeader
        icon={Repeat}
        title="Konfigurasi Swiss"
        description="Jumlah ronde, poin, dan urutan tie breaker. Ronde dibuat satu per satu setelah ronde sebelumnya selesai."
      />
      <CardContent className="grid gap-5 p-4 pt-0 sm:p-6 sm:pt-0">
        <Sub title="Jumlah ronde">
          <div className="grid gap-1.5 sm:max-w-xs">
            <Label className="font-semibold">Ronde</Label>
            {/*
              Empty means "ikut hitungan otomatis", so the derived number is the
              placeholder and never the value: written in, it would freeze
              today's field size into the row and stop following it as entrants
              are approved.
            */}
            <Input
              type="number"
              min={1}
              max={32}
              placeholder={String(derived)}
              value={c.swiss_rounds ?? ""}
              onChange={(e) =>
                patch({
                  swiss_rounds: e.target.value
                    ? Math.max(1, Math.min(32, Number(e.target.value) || 1))
                    : null,
                })
              }
            />
            <p className="text-xs text-muted-foreground">
              Kosongkan untuk mengikuti jumlah peserta — sekarang {derived} ronde. Tidak ada tim
              yang tersingkir, jadi semua peserta main sebanyak ini.
            </p>
          </div>
        </Sub>

        <PointsSection config={c} context={context} onChange={(points) => patch({ points })} />
        <TiebreakerSection config={c} onChange={(tiebreakers) => patch({ tiebreakers })} />
      </CardContent>
    </Card>
  );
}
