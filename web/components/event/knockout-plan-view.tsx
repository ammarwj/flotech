import { Info } from "lucide-react";

import { Card } from "@/components/ui/card";
import { crestGradient } from "@/lib/bracket";
import type { KnockoutPlan, KnockoutSlot } from "@/types/api";

/** One side of a planned tie: the slot's name, and who currently holds it. */
function Side({ slot }: { slot: KnockoutSlot | null }) {
  if (!slot) {
    return (
      <div className="flex items-center gap-2 px-3 py-2 text-sm text-muted-foreground">
        <span className="h-5 w-5 shrink-0 rounded-full border border-dashed border-border" />
        <span className="italic">Bye</span>
      </div>
    );
  }

  const team = slot.team;

  return (
    <div className="flex items-center gap-2 px-3 py-2">
      {team?.logo_url ? (
        // eslint-disable-next-line @next/next/no-img-element
        <img
          src={team.logo_url}
          alt=""
          className="h-5 w-5 shrink-0 rounded-full object-cover"
        />
      ) : (
        <span
          className="h-5 w-5 shrink-0 rounded-full"
          style={{ background: team ? crestGradient(team.name) : "var(--border)" }}
        />
      )}
      <span className="min-w-0 flex-1">
        <span className="block truncate text-sm font-semibold">{slot.label}</span>
        <span className="block truncate text-xs text-muted-foreground">
          {team ? `Sementara: ${team.name}` : "Belum ada hasil"}
        </span>
      </span>
    </div>
  );
}

/**
 * The bracket before it exists: which slot meets which in the first round, with
 * the team currently sitting in each slot. Lets the organizer (and later the
 * public) see "Juara Grup A v Runner-up Grup D" while the groups are still being
 * played.
 */
export function KnockoutPlanView({ plan }: { plan: KnockoutPlan }) {
  if (plan.ties.length === 0) {
    return (
      <p className="text-sm text-muted-foreground">
        Undi tim ke grup dulu untuk melihat rencana bracket.
      </p>
    );
  }

  return (
    <div className="grid gap-4">
      <div className="flex items-start gap-2 rounded-lg border border-border bg-[var(--bg-soft)] px-4 py-3 text-sm">
        <Info className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
        <p className="text-muted-foreground">
          Rencana bracket <span className="font-semibold text-foreground">{plan.bracket_size} besar</span> —{" "}
          {plan.qualifiers} tim lolos
          {plan.byes > 0 && `, ${plan.byes} BYE`}. Nama tim masih sementara dan mengikuti klasemen
          grup terkini;{" "}
          {plan.group_matches_pending > 0
            ? `${plan.group_matches_pending} laga grup belum selesai.`
            : "fase grup sudah selesai — bracket siap dibuat."}
        </p>
      </div>

      <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
        {plan.ties.map((tie) => (
          <Card key={tie.order} className="divide-y divide-border overflow-hidden">
            <Side slot={tie.home} />
            <Side slot={tie.away} />
          </Card>
        ))}
      </div>
    </div>
  );
}
