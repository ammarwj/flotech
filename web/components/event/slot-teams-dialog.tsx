"use client";

import { useEffect, useState } from "react";
import { Pencil, X } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { downstreamImpact, matchNumberLabel, sideLabel } from "@/lib/bracket";
import { SeedEntitySelect } from "./seed-entity-select";
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
  /**
   * Move this tie to another slot of the same round, the two trading contents.
   *
   * A different question from the selects above, and in the same dialog
   * because an organizer reading a wrong chart cannot tell in advance which of
   * the two they need: "this pairing is wrong" and "this pairing is in the
   * wrong place" look identical until you try to fix one.
   */
  onMove: (targetId: string) => void;
}

/**
 * What a slot holds, in the words the chart already uses: the two teams, the
 * caption for a side still waiting ("Pemenang P5"), or the word for a slot
 * nobody is in yet. Reuses sideLabel() rather than spelling it again — a
 * picker that named a slot differently from the card beside it would be two
 * answers to the same question.
 */
/** Nothing is ever out of bounds in the target picker — every slot it lists
 *  is already filtered to the ones the server will accept. */
const EMPTY_IDS = new Set<string>();

function slotSummary(m: Match): string {
  return `${sideLabel(m, "home")} vs ${sideLabel(m, "away")}`;
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
  onMove,
}: SlotTeamsDialogProps) {
  const [home, setHome] = useState(match.home_team_id ?? "");
  const [away, setAway] = useState(match.away_team_id ?? "");
  const [target, setTarget] = useState("");

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

  /**
   * The only team genuinely out of bounds is the one on the other side — a slot
   * cannot hold the same team twice. Teams placed elsewhere stay pickable and
   * get a note instead, because picking one is the swap the dialog exists for.
   */
  const blocked = (opposite: string) => (opposite ? new Set([opposite]) : new Set<string>());

  /**
   * Slots this tie may move to: same round, no result yet.
   *
   * The round is fixed because the rounds are not interchangeable — a tie
   * moved up would claim a place its teams never played for. The scoreline is
   * what rules a slot out, not its status: a bye is stored finished with no
   * score, and moving one onto the right branch is a correction this offers.
   * The server enforces both; this only keeps the list from offering a move it
   * would then refuse.
   */
  const moveTargets = bracket
    .filter(
      (m) =>
        m.id !== match.id &&
        m.round === match.round &&
        m.stage === match.stage &&
        m.bracket !== "third_place" &&
        m.home_score === null &&
        m.away_score === null,
    )
    .sort((a, b) => a.order - b.order)
    .map((m) => ({
      id: m.id,
      name: `${matchNumberLabel(m) ?? `Slot ${m.order + 1}`} — ${slotSummary(m)}`,
    }));

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
              <SeedEntitySelect
                id="slot-home"
                value={home || null}
                onChange={(v) => setHome(v ?? "")}
                pool={teams}
                disabledIds={blocked(away)}
                noteIds={placedElsewhere}
                noteLabel="di slot lain"
                emptyLabel="Pilih tim…"
                placeholder="Cari tim…"
                ariaLabel="Tim tuan rumah"
              />
              {fieldErrors?.home_team_id && (
                <p className="text-xs text-[var(--danger)]">{fieldErrors.home_team_id}</p>
              )}
            </div>
            <div className="grid gap-1.5">
              <Label htmlFor="slot-away" className="font-semibold">
                Tim tamu
              </Label>
              {/* An empty away side is a walkover, not an unfinished form. */}
              <SeedEntitySelect
                id="slot-away"
                value={away || null}
                onChange={(v) => setAway(v ?? "")}
                pool={teams}
                disabledIds={blocked(home)}
                noteIds={placedElsewhere}
                noteLabel="di slot lain"
                emptyLabel="Bye (menang tanpa bertanding)"
                placeholder="Cari tim…"
                ariaLabel="Tim tamu"
              />
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

          {/* The other question this dialog answers: not who is in the slot,
              but which branch the tie sits on. Below the selects and behind a
              divider because it is the rarer of the two and replaces, rather
              than accompanies, what is typed above — moving posts on its own. */}
          {moveTargets.length > 0 && (
            <div className="grid gap-2 border-t border-border pt-4">
              <Label htmlFor="slot-move" className="font-semibold">
                Pindahkan ke slot lain
              </Label>
              <p className="text-xs text-muted-foreground">
                Pasangan ini pindah ke slot yang dipilih, dan isi slot itu pindah ke
                sini. Siapa melawan siapa tidak berubah — hanya posisinya di bagan.
              </p>
              <div className="flex flex-wrap items-center gap-2">
                <SeedEntitySelect
                  id="slot-move"
                  value={target || null}
                  onChange={(v) => setTarget(v ?? "")}
                  pool={moveTargets}
                  disabledIds={EMPTY_IDS}
                  emptyLabel="Pilih slot tujuan…"
                  placeholder="Cari slot…"
                  ariaLabel="Slot tujuan"
                  className="min-w-[12rem] flex-1"
                />
                <Button
                  variant="outline"
                  disabled={!target || pending}
                  onClick={() => onMove(target)}
                >
                  Pindahkan
                </Button>
              </div>
            </div>
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
