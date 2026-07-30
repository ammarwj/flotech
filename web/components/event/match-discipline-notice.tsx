"use client";

import { Badge } from "@/components/ui/badge";
import { banReasonLabel } from "@/lib/scoring";
import type { DisciplineBan, DisciplineRules, SportDef } from "@/types/api";

/**
 * The players a ban touches in this fixture, and why.
 *
 * Two rows, because a fixture answers two different questions depending on
 * whether it has been played. Upcoming: who may not take the field. Played: who
 * sat one out here — the row that makes the feature auditable after the fact
 * instead of only readable before kick-off, and the reason a card that shows
 * nothing can be trusted to mean nothing.
 *
 * A warning, not a block. Nothing in the app records who actually played — goal
 * sports have no lineup entry — so the panitia is the one who decides; this row
 * only makes sure they are deciding with the tally in front of them instead of
 * counting cards across the schedule by hand.
 *
 * Tone is `warning` rather than `danger` for the same reason nothing here has
 * gone wrong yet, and the served row drops to `neutral` because on a fixture
 * already in the books there is nothing left to act on at all.
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
  const upcoming = bans.filter((b) => b.status === "upcoming");
  const served = bans.filter((b) => b.status === "served");

  if (upcoming.length === 0 && served.length === 0) return null;

  return (
    <div className="grid gap-1">
      {upcoming.length > 0 && (
        <div className="flex flex-wrap items-center gap-x-2 gap-y-1 rounded-lg bg-[color-mix(in_srgb,var(--warning)_10%,transparent)] px-2 py-1.5 text-xs">
          <Badge variant="warning" dot>
            Larangan bermain
          </Badge>
          <BanList bans={upcoming} sport={sport} rules={rules} played={false} />
        </div>
      )}

      {served.length > 0 && (
        <div className="flex flex-wrap items-center gap-x-2 gap-y-1 rounded-lg bg-[var(--bg-soft)] px-2 py-1.5 text-xs">
          <Badge variant="neutral">Menjalani larangan</Badge>
          <BanList bans={served} sport={sport} rules={rules} played />
        </div>
      )}
    </div>
  );
}

/**
 * The names themselves.
 *
 * `bans_remaining` counts the fixture it is reported on, which reads correctly
 * on one that has yet to be played ("sisa 2 laga", this one included) and
 * misleadingly on one already in the books — there it would name a match the
 * player has just sat out as though it were still to come. So the played row
 * counts down by one and says so.
 */
function BanList({
  bans,
  sport,
  rules,
  played,
}: {
  bans: DisciplineBan[];
  sport: SportDef | null;
  rules: DisciplineRules | null;
  played: boolean;
}) {
  return (
    <span className="text-muted-foreground">
      {bans.map((ban, i) => (
        <span key={ban.player_id}>
          {i > 0 && <span className="mx-1 opacity-50">·</span>}
          <span className="font-medium text-foreground">
            {ban.player_name}
            {ban.jersey_number && ` (#${ban.jersey_number})`}
          </span>{" "}
          — {banReasonLabel(ban.reason, sport, rules)}
          {ban.bans_remaining > 1 &&
            (played
              ? `, sisa ${ban.bans_remaining - 1} laga lagi`
              : `, sisa ${ban.bans_remaining} laga`)}
        </span>
      ))}
    </span>
  );
}
