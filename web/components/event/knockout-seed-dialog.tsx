"use client";

import { useEffect, useState } from "react";
import { Network, X } from "lucide-react";

import { Button } from "@/components/ui/button";
import { BracketSeedEditor, type SeedingMode, type SeedTeam } from "./bracket-seed-editor";
import type { SeedPair, SeedingPayload } from "@/lib/api/matches";
import type { KnockoutPlan } from "@/types/api";

/**
 * Build the knockout bracket of a hybrid category, either from the standings or
 * from pairings the organizer names themselves.
 *
 * Opens on the automatic plan, so the common case is "look, agree, confirm" and
 * only the ties actually in dispute need touching.
 */
interface KnockoutSeedDialogProps {
  plan: KnockoutPlan | undefined;
  hasBracket: boolean;
  pending: boolean;
  onClose: () => void;
  onSubmit: (payload: SeedingPayload) => void;
}

export function KnockoutSeedDialog({ open, ...props }: KnockoutSeedDialogProps & { open: boolean }) {
  // Mounted only while open, so each open starts from the current plan.
  return open ? <Dialog {...props} /> : null;
}

function Dialog({ plan, hasBracket, pending, onClose, onSubmit }: KnockoutSeedDialogProps) {
  const [mode, setMode] = useState<SeedingMode>("auto");
  const [pairs, setPairs] = useState<SeedPair[]>(() =>
    (plan?.ties ?? []).map((tie) => ({
      order: tie.order,
      home_team_id: tie.home?.team?.id ?? null,
      away_team_id: tie.away?.team?.id ?? null,
    }))
  );

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => e.key === "Escape" && onClose();
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [onClose]);

  // The pool is exactly who the backend will accept: the teams holding the
  // planned slots, i.e. the qualifiers.
  const pool: SeedTeam[] = (plan?.ties ?? [])
    .flatMap((tie) => [tie.home?.team, tie.away?.team])
    .filter((t): t is NonNullable<typeof t> => !!t)
    .map((t) => ({ id: t.id, name: t.name }));

  const ready = (plan?.group_matches_pending ?? 0) === 0;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" onClick={onClose}>
      <div
        role="dialog"
        aria-modal="true"
        aria-label="Buat bracket knockout"
        onClick={(e) => e.stopPropagation()}
        className="flex max-h-[85vh] w-full max-w-lg flex-col overflow-hidden rounded-xl border border-border bg-card shadow-[var(--shadow-lg)]"
      >
        <div className="flex items-start gap-3 border-b border-border p-5">
          <span className="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-[var(--tint)] text-[var(--brand-600)]">
            <Network className="h-5 w-5" />
          </span>
          <div className="min-w-0 flex-1">
            <h2 className="text-base font-bold" style={{ fontFamily: "var(--font-display)" }}>
              {hasBracket ? "Buat Ulang Bracket" : "Buat Bracket Knockout"}
            </h2>
            {plan && (
              <p className="mt-0.5 text-sm text-muted-foreground">
                {plan.qualifiers} tim lolos · bracket {plan.bracket_size} besar
                {plan.byes > 0 && ` · ${plan.byes} bye`}.
              </p>
            )}
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
          {!ready && (
            <p className="rounded-md border border-[color-mix(in_srgb,var(--warning)_40%,transparent)] bg-[color-mix(in_srgb,var(--warning)_8%,transparent)] px-3 py-2 text-xs text-[var(--warning)]">
              Masih ada {plan?.group_matches_pending} pertandingan grup yang belum selesai atau
              belum dikonfirmasi.
            </p>
          )}

          <BracketSeedEditor
            size={plan?.bracket_size ?? 2}
            pool={pool}
            mode={mode}
            value={pairs}
            onModeChange={setMode}
            onChange={setPairs}
            autoHint="Unggulan diambil dari klasemen grup, dan tim satu grup dihindarkan bertemu di babak pertama."
          />

          {hasBracket && (
            <p className="rounded-md border border-[color-mix(in_srgb,var(--warning)_40%,transparent)] bg-[color-mix(in_srgb,var(--warning)_8%,transparent)] px-3 py-2 text-xs text-[var(--warning)]">
              Bracket yang ada akan diganti beserta seluruh hasilnya. Untuk membetulkan satu
              slot saja, pakai ikon pensil di bracket.
            </p>
          )}
        </div>

        <div className="flex items-center justify-end gap-2 border-t border-border p-4">
          <Button variant="ghost" onClick={onClose} disabled={pending}>
            Batal
          </Button>
          <Button
            onClick={() =>
              onSubmit(mode === "manual" ? { seeding: "manual", pairs } : { seeding: "auto" })
            }
            disabled={pending || !ready}
          >
            <Network className="h-4 w-4" />
            {pending ? "Membuat…" : hasBracket ? "Buat Ulang" : "Buat Bracket"}
          </Button>
        </div>
      </div>
    </div>
  );
}
