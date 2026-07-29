"use client";

import { Badge } from "@/components/ui/badge";
import { banReasonLabel } from "@/lib/scoring";
import type { DisciplineBan, DisciplineRules, SportDef } from "@/types/api";

/**
 * The players who may not take the field in this fixture, and why.
 *
 * A warning, not a block. Nothing in the app records who actually played — goal
 * sports have no lineup entry — so the panitia is the one who decides; this row
 * only makes sure they are deciding with the tally in front of them instead of
 * counting cards across the schedule by hand.
 *
 * Tone is `warning` rather than `danger` for the same reason: nothing here has
 * gone wrong yet.
 */
export function MatchDisciplineNotice({
  bans,
  sport,
  rules,
}: {
  bans: DisciplineBan[];
  sport: SportDef | null;
  rules: DisciplineRules | null;
}) {
  if (bans.length === 0) return null;

  return (
    <div className="flex flex-wrap items-center gap-x-2 gap-y-1 rounded-lg bg-[color-mix(in_srgb,var(--warning)_10%,transparent)] px-2 py-1.5 text-xs">
      <Badge variant="warning" dot>
        Larangan bermain
      </Badge>
      <span className="text-muted-foreground">
        {bans.map((ban, i) => (
          <span key={ban.player_id}>
            {i > 0 && <span className="mx-1 opacity-50">·</span>}
            <span className="font-medium text-foreground">
              {ban.player_name}
              {ban.jersey_number && ` (#${ban.jersey_number})`}
            </span>{" "}
            — {banReasonLabel(ban.reason, sport, rules)}
            {ban.bans_remaining > 1 && `, sisa ${ban.bans_remaining} laga`}
          </span>
        ))}
      </span>
    </div>
  );
}
