"use client";

import { useEffect, useState } from "react";
import { Pencil, X } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { downstreamImpact } from "@/lib/bracket";
import type { FieldErrors } from "@/lib/api/errors";
import type { SeedTeam } from "./bracket-seed-editor";
import type { MatchTeamsPayload } from "@/lib/api/matches";
import type { Match } from "@/types/api";

/**
 * Correct a wrong seed in a first-round bracket slot.
 *
 * Whatever the outgoing team reached in later rounds is undone server-side, so
 * the count of fixtures that will lose their teams and results is worked out
 * here first — the organizer should never find that out afterwards.
 */
interface SlotTeamsDialogProps {
  /** The slot being edited. */
  match: Match;
  /** Every fixture of this bracket, for tracing what the change reaches. */
  bracket: Match[];
  /**
   * Teams the backend will accept here: the group qualifiers for a hybrid
   * bracket, the approved teams for single elimination.
   */
  teams: SeedTeam[];
  pending: boolean;
  fieldErrors?: FieldErrors;
  onClose: () => void;
  onSubmit: (payload: MatchTeamsPayload) => void;
}

export function SlotTeamsDialog({ open, ...props }: SlotTeamsDialogProps & { open: boolean }) {
  // Mounted only while open, so each open starts from the slot as it stands.
  return open ? <Dialog {...props} /> : null;
}

function Dialog({
  match,
  bracket,
  teams,
  pending,
  fieldErrors,
  onClose,
  onSubmit,
}: SlotTeamsDialogProps) {
  const [home, setHome] = useState(match.home_team_id ?? "");
  const [away, setAway] = useState(match.away_team_id ?? "");

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => e.key === "Escape" && onClose();
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [onClose]);

  const affected = downstreamImpact(match, bracket);
  const canSave = home !== "" && home !== away;

  // Teams sitting in another first-round slot: picking one exchanges the two
  // rather than being refused, which is the only useful move in a full bracket.
  const placedElsewhere = new Set(
    bracket
      .filter((m) => m.round === 1 && m.id !== match.id)
      .flatMap((m) => [m.home_team_id, m.away_team_id])
      .filter((id): id is string => !!id)
  );
  const swaps = placedElsewhere.has(home) || placedElsewhere.has(away);

  const option = (t: SeedTeam, opposite: string) => (
    <option key={t.id} value={t.id} disabled={t.id === opposite}>
      {t.name}
      {placedElsewhere.has(t.id) ? " — sudah di slot lain" : ""}
    </option>
  );

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" onClick={onClose}>
      <div
        role="dialog"
        aria-modal="true"
        aria-label="Ganti tim di slot bracket"
        onClick={(e) => e.stopPropagation()}
        className="flex max-h-[85vh] w-full max-w-lg flex-col overflow-hidden rounded-xl border border-border bg-card shadow-[var(--shadow-lg)]"
      >
        <div className="flex items-start gap-3 border-b border-border p-5">
          <span className="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-[var(--tint)] text-[var(--brand-600)]">
            <Pencil className="h-5 w-5" />
          </span>
          <div className="min-w-0 flex-1">
            <h2 className="text-base font-bold" style={{ fontFamily: "var(--font-display)" }}>
              Ganti Tim di Slot
            </h2>
            <p className="mt-0.5 text-sm text-muted-foreground">
              Perbaiki seeding yang keliru tanpa membuat ulang seluruh bracket.
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
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="grid gap-1.5">
              <Label htmlFor="slot-home" className="font-semibold">
                Tim tuan rumah<span className="text-[var(--danger)]"> *</span>
              </Label>
              <Select id="slot-home" value={home} onChange={(e) => setHome(e.target.value)}>
                <option value="">Pilih tim…</option>
                {teams.map((t) => option(t, away))}
              </Select>
              {fieldErrors?.home_team_id && (
                <p className="text-xs text-[var(--danger)]">{fieldErrors.home_team_id}</p>
              )}
            </div>
            <div className="grid gap-1.5">
              <Label htmlFor="slot-away" className="font-semibold">
                Tim tamu
              </Label>
              <Select id="slot-away" value={away} onChange={(e) => setAway(e.target.value)}>
                {/* An empty away side is a walkover, not an unfinished form. */}
                <option value="">Bye (menang tanpa bertanding)</option>
                {teams.map((t) => option(t, home))}
              </Select>
              {fieldErrors?.away_team_id && (
                <p className="text-xs text-[var(--danger)]">{fieldErrors.away_team_id}</p>
              )}
            </div>
          </div>

          {swaps && (
            <p className="rounded-md border border-border bg-[var(--surface-2)] px-3 py-2 text-xs text-muted-foreground">
              Tim yang sudah punya slot akan bertukar tempat dengan tim yang keluar dari
              slot ini.
            </p>
          )}

          {affected > 0 && (
            <p className="rounded-md border border-[color-mix(in_srgb,var(--warning)_40%,transparent)] bg-[color-mix(in_srgb,var(--warning)_8%,transparent)] px-3 py-2 text-xs text-[var(--warning)]">
              Mengganti tim di slot ini akan mengosongkan {affected} pertandingan babak
              berikutnya beserta hasilnya.
            </p>
          )}
        </div>

        <div className="flex items-center justify-end gap-2 border-t border-border p-4">
          <Button variant="ghost" onClick={onClose} disabled={pending}>
            Batal
          </Button>
          <Button
            onClick={() => onSubmit({ home_team_id: home, away_team_id: away || null })}
            disabled={pending || !canSave}
          >
            <Pencil className="h-4 w-4" />
            {pending ? "Menyimpan…" : "Simpan"}
          </Button>
        </div>
      </div>
    </div>
  );
}
