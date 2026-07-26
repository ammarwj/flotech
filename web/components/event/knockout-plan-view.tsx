"use client";

import { AlertTriangle, CalendarClock, Info, MapPin } from "lucide-react";

import { Card } from "@/components/ui/card";
import { crestGradient } from "@/lib/bracket";
import { knockoutReadiness } from "@/lib/hybrid";
import { tabDateLabel, timeOf } from "@/lib/match-dates";
import { useEventTimezone } from "./event-timezone";
import type { KnockoutPlan, KnockoutSlot } from "@/types/api";

/** One side of a planned tie: the slot's name, and who currently holds it. */
function Side({
  slot,
  planned,
}: {
  slot: KnockoutSlot | null;
  planned: boolean;
}) {
  if (!slot) {
    return (
      <div className="flex items-center gap-2 px-3 py-2 text-sm text-muted-foreground">
        <span className="h-5 w-5 shrink-0 rounded-full border border-dashed border-border" />
        {/* An empty side means opposite things either way round: in a draw the
            organizer made it is a bye they chose, in an untouched one it is
            simply a decision not taken yet. */}
        <span className="italic">{planned ? "Bye" : "Belum ditentukan"}</span>
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
          style={{
            background: team ? crestGradient(team.name) : "var(--border)",
          }}
        />
      )}
      <span className="min-w-0 flex-1">
        <span className="block truncate text-sm font-semibold">
          {slot.label}
        </span>
        <span className="block truncate text-xs text-muted-foreground">
          {team ? `Sementara: ${team.name}` : "Belum ada hasil"}
        </span>
      </span>
    </div>
  );
}

/**
 * The bracket before it exists: which slot meets which in the first round, with
 * the team currently sitting in each slot. Lets the organizer see "Juara Grup A
 * v Runner-up Grup D" while the groups are still being played.
 *
 * Dashboard only. The draw stays theirs to rearrange until they generate it,
 * and a plan on the public page is a promise they have not made yet — the
 * spectator view gets the bracket, once it is real.
 *
 * Read-only on purpose: arranging the draw lives inside the "Buat Bracket
 * Knockout" dialog. An edit button here sat right beside that one and read as a
 * rival action nobody could tell apart from it.
 */
export function KnockoutPlanView({ plan }: { plan: KnockoutPlan }) {
  // Ties the organizer already timed carry a UTC instant; the zone that makes
  // it readable is the venue's, not the viewer's.
  const tz = useEventTimezone();

  if (plan.ties.length === 0) {
    return (
      <p className="text-sm text-muted-foreground">
        Undi tim ke grup dulu untuk melihat rencana bracket.
      </p>
    );
  }

  const manual = plan.source === "manual";
  const broken = plan.stale || plan.unplaced_slots.length > 0;
  const { ready, reason } = knockoutReadiness(plan);

  return (
    <div className="grid gap-4">
      <div className="flex flex-wrap items-start gap-2 rounded-lg border border-border bg-[var(--bg-soft)] px-4 py-3 text-sm">
        <Info className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
        <p className="min-w-0 flex-1 text-muted-foreground">
          {manual ? "Rencana bracket kamus" : "Rencana bracket"}{" "}
          <span className="font-semibold text-foreground">
            {plan.bracket_size} besar
          </span>{" "}
          — {plan.qualifiers} tim lolos
          {plan.byes > 0 && `, ${plan.byes} BYE`}.{" "}
          {manual
            ? "Pasangannya kamu tentukan sendiri; nama tim mengikuti klasemen grup terkini."
            : "Belum ada pasangan yang ditentukan — susun sendiri lewat “Buat Bracket Knockout” (bisa disimpan sekarang, tanpa menunggu grup selesai), atau biarkan kosong dan unggulan diambil dari klasemen saat bracket dibuat."}{" "}
          {/* The same answer the generate button uses, so this can't promise a
              readiness the button then refuses. */}
          {ready ? "Fase grup sudah selesai — bracket siap dibuat." : reason}
        </p>
      </div>

      {/* A saved draw that no longer covers the bracket. Activation refuses it,
          so say why here rather than let the organizer discover it at the end. */}
      {broken && (
        <p className="flex items-start gap-2 rounded-md border border-[color-mix(in_srgb,var(--warning)_40%,transparent)] bg-[color-mix(in_srgb,var(--warning)_8%,transparent)] px-3 py-2 text-xs text-[var(--warning)]">
          <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
          <span>
            Aturan kualifikasi berubah setelah rencana ini disimpan
            {plan.stale &&
              " — ada pasangan yang menunjuk slot yang sudah tidak ada"}
            {plan.unplaced_slots.length > 0 &&
              ` — belum ditempatkan: ${plan.unplaced_slots.map((s) => s.label).join(", ")}`}
            . Susun ulang rencananya sebelum bracket bisa diaktifkan.
          </span>
        </p>
      )}

      <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
        {plan.ties.map((tie) => (
          <Card
            key={tie.order}
            className="divide-y divide-border overflow-hidden"
          >
            <Side slot={tie.home} planned={manual} />
            <Side slot={tie.away} planned={manual} />
            {(tie.scheduled_at || tie.venue) && (
              <div className="flex flex-wrap items-center gap-3 px-3 py-1.5 text-xs text-muted-foreground">
                {tie.scheduled_at && (
                  <span className="inline-flex items-center gap-1">
                    <CalendarClock className="h-3.5 w-3.5" />
                    {tabDateLabel(tie.scheduled_at, tz)} ·{" "}
                    {timeOf(tie.scheduled_at, tz)}
                  </span>
                )}
                {tie.venue && (
                  <span className="inline-flex items-center gap-1">
                    <MapPin className="h-3.5 w-3.5" />
                    {tie.venue}
                  </span>
                )}
              </div>
            )}
          </Card>
        ))}
      </div>
    </div>
  );
}
