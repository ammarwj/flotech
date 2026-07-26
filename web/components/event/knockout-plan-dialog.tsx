"use client";

import { useEffect, useState } from "react";
import { CalendarClock, Network, X } from "lucide-react";

import { Button } from "@/components/ui/button";
import { unplacedTeams } from "@/lib/bracket";
import { knockoutReadiness } from "@/lib/hybrid";
import {
  BracketSeedEditor,
  type SeedingMode,
  type SeedTeam,
} from "./bracket-seed-editor";
import type { PlannedTie, SeedPair } from "@/lib/api/matches";
import type { KnockoutPlan } from "@/types/api";

/**
 * The one place a hybrid bracket is decided: draw the first round in *slots*
 * ("Juara Grup A" v "Runner-up Grup B"), and — once the groups are done —
 * generate the bracket from that draw without leaving the dialog.
 *
 * The two used to be separate dialogs behind two near-identical buttons, which
 * nobody could tell apart. They are one action seen at two moments, so they are
 * one dialog with two footer actions:
 *
 *  - **Simpan Rencana** works from the day the group schedule exists. Waiting
 *    for twelve group games before you may write down "juara A v runner-up B"
 *    is exactly the wait this feature exists to remove.
 *  - **Buat Bracket** is the one that waits, because a bracket built on an
 *    unfinished table would seat whoever happens to lead right now.
 *
 * Reuses BracketSeedEditor by mapping slots onto its team shape — the pairing
 * interaction is identical, only the things being paired differ.
 */
interface KnockoutPlanDialogProps {
  plan: KnockoutPlan;
  /** A bracket already exists: generating replaces it and everything played. */
  hasBracket: boolean;
  pending: boolean;
  /** The venue's zone, for the per-tie kickoff inputs. */
  tz: string;
  /** Named courts to pick per tie; empty falls back to a free-text field. */
  courts: string[];
  onClose: () => void;
  /**
   * Manual: the ties to save. Automatic: null, i.e. drop the saved draw.
   * `generate` also builds the bracket from what was just saved.
   */
  onSubmit: (ties: PlannedTie[] | null, generate: boolean) => void;
}

export function KnockoutPlanDialog({
  open,
  ...props
}: KnockoutPlanDialogProps & { open: boolean }) {
  return open ? <Dialog {...props} /> : null;
}

function Dialog({
  plan,
  hasBracket,
  pending,
  tz,
  courts,
  onClose,
  onSubmit,
}: KnockoutPlanDialogProps) {
  const [mode, setMode] = useState<SeedingMode>(
    plan.source === "manual" ? "manual" : "auto",
  );

  // Seeded from the plan on screen, which is empty until the organizer draws
  // one — so a first visit starts blank and nothing here was ever suggested by
  // the app. Once a draw exists the editor opens on it, because the task is
  // then to rearrange what is in force, not to retype it.
  const [ties, setTies] = useState<PlannedTie[]>(() =>
    plan.ties
      .filter((tie) => tie.home || tie.away)
      .map((tie) => ({
        order: tie.order,
        home: tie.home?.key ?? null,
        away: tie.away?.key ?? null,
        scheduled_at: tie.scheduled_at,
        venue: tie.venue,
      })),
  );

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => e.key === "Escape" && onClose();
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [onClose]);

  // The editor speaks SeedPair; a slot key stands in for a team id.
  const pool: SeedTeam[] = plan.slots.map((s) => ({
    id: s.key,
    name: s.label,
  }));
  const pairs: SeedPair[] = ties.map((t) => ({
    order: t.order,
    home_team_id: t.home,
    away_team_id: t.away,
    scheduled_at: t.scheduled_at,
    venue: t.venue,
  }));

  // Every qualifier slot has to hold a place; the backend refuses the payload
  // otherwise, because whoever wins that place would never get a fixture.
  const unplaced = mode === "manual" ? unplacedTeams(pool, pairs) : [];
  const incomplete = unplaced.length > 0;

  // The group stage blocks *generation* only — never saving the draw.
  const { ready, reason } = knockoutReadiness(plan);

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
      onClick={onClose}
    >
      <div
        role="dialog"
        aria-modal="true"
        aria-label="Atur bracket knockout"
        onClick={(e) => e.stopPropagation()}
        className="flex max-h-[85vh] w-full max-w-lg flex-col overflow-hidden rounded-xl border border-border bg-card shadow-[var(--shadow-lg)]"
      >
        <div className="flex items-start gap-3 border-b border-border p-5">
          <span className="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-[var(--tint)] text-[var(--brand-600)]">
            <Network className="h-5 w-5" />
          </span>
          <div className="min-w-0 flex-1">
            <h2
              className="text-base font-bold"
              style={{ fontFamily: "var(--font-display)" }}
            >
              {hasBracket ? "Buat Ulang Bracket" : "Bracket Knockout"}
            </h2>
            <p className="mt-0.5 text-sm text-muted-foreground">
              Bracket {plan.bracket_size} besar · {plan.qualifiers} tim lolos
              {plan.byes > 0 && ` · ${plan.byes} bye`}. Susun pasangannya
              sekarang, buat bracketnya setelah fase grup selesai.
            </p>
          </div>
          <button
            onClick={onClose}
            className="rounded-md p-1 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
            aria-label="Tutup"
          >
            <X className="h-4 w-4" />
          </button>
        </div>

        <div className="grid gap-4 overflow-y-auto p-5">
          <BracketSeedEditor
            size={plan.bracket_size}
            pool={pool}
            mode={mode}
            value={pairs}
            tz={tz}
            courts={courts}
            entityLabel="Slot"
            emptyLabel="Kosong / bye"
            onModeChange={setMode}
            onChange={(next) =>
              setTies(
                next.map((p) => ({
                  order: p.order,
                  home: p.home_team_id,
                  away: p.away_team_id,
                  scheduled_at: p.scheduled_at ?? null,
                  venue: p.venue ?? null,
                })),
              )
            }
            autoHint="Unggulan diambil dari klasemen grup, dan tim satu grup dihindarkan bertemu di babak pertama."
          />

          {/* Aimed at the button it disables, not at the dialog: saving the draw
              is what the organizer came here to do early, and reading this as
              "come back later" would send them away for nothing. */}
          {!ready && (
            <p className="rounded-md border border-[color-mix(in_srgb,var(--warning)_40%,transparent)] bg-[color-mix(in_srgb,var(--warning)_8%,transparent)] px-3 py-2 text-xs text-[var(--warning)]">
              Rencananya bisa disimpan sekarang — yang belum bisa cuma membuat
              bracketnya. {reason}
            </p>
          )}

          {hasBracket && (
            <p className="rounded-md border border-[color-mix(in_srgb,var(--warning)_40%,transparent)] bg-[color-mix(in_srgb,var(--warning)_8%,transparent)] px-3 py-2 text-xs text-[var(--warning)]">
              Membuat bracket akan mengganti bracket yang ada beserta seluruh
              hasilnya. Untuk membetulkan satu slot saja, pakai ikon pensil di
              bracket.
            </p>
          )}
        </div>

        <div className="flex flex-wrap items-center justify-end gap-2 border-t border-border p-4">
          <Button variant="ghost" onClick={onClose} disabled={pending}>
            Batal
          </Button>
          <Button
            variant="outline"
            onClick={() => onSubmit(mode === "manual" ? ties : null, false)}
            disabled={pending || incomplete}
          >
            <CalendarClock className="h-4 w-4" />
            {pending ? "Menyimpan…" : "Simpan Rencana"}
          </Button>
          <Button
            onClick={() => onSubmit(mode === "manual" ? ties : null, true)}
            disabled={pending || incomplete || !ready}
          >
            <Network className="h-4 w-4" />
            {pending ? "Membuat…" : hasBracket ? "Buat Ulang" : "Buat Bracket"}
          </Button>
        </div>
      </div>
    </div>
  );
}
