"use client";

import { DateTimePicker } from "@/components/ui/date-time-picker";
import { dialogConsequences } from "@/components/ui/dialog";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { unplacedTeams } from "@/lib/bracket";
import { fromEventInput, toEventInput, tzLabel } from "@/lib/match-dates";
import { CourtSelect } from "./court-select";
import { SeedEntitySelect } from "./seed-entity-select";
import type { SeedPair } from "@/lib/api/matches";

export type SeedingMode = "auto" | "manual";

/** A team as the seed editor needs it: an id and something to show. */
export interface SeedTeam {
  id: string;
  name: string;
}

/**
 * Who meets who in the opening round.
 *
 * Deliberately not a dialog: the hybrid flow opens it on its own, while single
 * elimination folds it into the schedule settings. Paired selects rather than
 * drag-and-drop — organizers run these events from a phone, and dragging is
 * both a new dependency and the worse interaction there.
 */
export function BracketSeedEditor({
  slots: slotCount,
  pool,
  mode,
  value,
  tz,
  courts = [],
  onModeChange,
  onChange,
  autoHint,
  entityLabel = "Tim",
  emptyLabel = "Bye",
}: {
  /**
   * Opening ties to draw, one row each.
   *
   * The count rather than the bracket size, because a draw is no longer
   * necessarily a power of two: a 36-team bracket that byes late opens with 18
   * ties, which `size / 2` cannot express. Its callers each know the number
   * already — the plan dialog from `bracket_size`, the schedule dialog from
   * firstRoundSlots() — and halving a size here would be a second place for
   * that arithmetic to disagree with the API's `pairs.*.order` rule.
   */
  slots: number;
  pool: SeedTeam[];
  mode: SeedingMode;
  value: SeedPair[];
  /** The venue's zone: kickoffs are typed and read as its wall clock. */
  tz: string;
  /** Named courts to pick from per tie; empty falls back to a free-text field. */
  courts?: string[];
  onModeChange: (mode: SeedingMode) => void;
  onChange: (pairs: SeedPair[]) => void;
  /** What automatic seeding does here, since it differs per engine. */
  autoHint: string;
  /**
   * What the pool holds, singular. The plan editor pairs qualifier *slots*
   * ("Juara Grup A") rather than teams, and the field names here — home_team_id
   * and friends — are the only thing that still says otherwise.
   */
  entityLabel?: string;
  /** What an empty second side means; "Bye" for a real draw. */
  emptyLabel?: string;
}) {
  const slots = Math.max(1, slotCount);
  const unplaced = unplacedTeams(pool, value);

  const pairAt = (order: number): SeedPair =>
    value.find((p) => p.order === order) ?? {
      order,
      home_team_id: null,
      away_team_id: null,
      scheduled_at: null,
      venue: null,
    };

  const setField = <K extends keyof SeedPair>(order: number, key: K, val: SeedPair[K]) => {
    const next = value.filter((p) => p.order !== order);
    next.push({ ...pairAt(order), [key]: val });
    onChange(next.sort((a, b) => a.order - b.order));
  };

  const setSide = (order: number, side: "home_team_id" | "away_team_id", id: string | null) =>
    setField(order, side, id || null);

  // A team already placed in another tie can't be picked twice — the backend
  // refuses it, so the select shouldn't offer it as if it were free.
  const takenElsewhere = (order: number) =>
    new Set(
      value
        .filter((p) => p.order !== order)
        .flatMap((p) => [p.home_team_id, p.away_team_id])
        .filter((id): id is string => !!id)
    );

  return (
    <div className="grid gap-4">
      <div className="grid gap-1.5">
        <Label className="font-semibold">Seeding babak pertama</Label>
        <Select value={mode} onChange={(e) => onModeChange(e.target.value as SeedingMode)}>
          <option value="auto">Otomatis</option>
          <option value="manual">Manual</option>
        </Select>
        <p className="text-xs text-muted-foreground">
          {mode === "auto" ? autoHint : "Tentukan sendiri siapa melawan siapa di babak pertama."}
        </p>
      </div>

      {mode === "manual" && (
        <div className="grid gap-2">
          <div className="grid gap-2 rounded-lg border border-border p-2">
            {Array.from({ length: slots }, (_, order) => {
              const pair = pairAt(order);
              const taken = takenElsewhere(order);
              /* The other side of this same tie joins the placed set: nobody
                 plays themselves, and the backend refuses it either way. */
              const blocked = (opposite: string | null) =>
                opposite ? new Set([...taken, opposite]) : taken;

              return (
                <div key={order} className="grid gap-1.5 border-b border-border pb-2 last:border-0 last:pb-0">
                  <div className="flex items-center gap-2">
                    <span className="w-6 shrink-0 text-xs font-semibold text-muted-foreground">
                      {order + 1}.
                    </span>
                    <SeedEntitySelect
                      value={pair.home_team_id ?? null}
                      onChange={(v) => setSide(order, "home_team_id", v)}
                      pool={pool}
                      disabledIds={blocked(pair.away_team_id)}
                      emptyLabel="—"
                      placeholder={`Cari ${entityLabel.toLowerCase()}…`}
                      ariaLabel={`${entityLabel} pertama pertandingan ${order + 1}`}
                      className="flex-1"
                    />
                    <span className="shrink-0 text-xs text-muted-foreground">vs</span>
                    <SeedEntitySelect
                      value={pair.away_team_id ?? null}
                      onChange={(v) => setSide(order, "away_team_id", v)}
                      pool={pool}
                      disabledIds={blocked(pair.home_team_id)}
                      emptyLabel={emptyLabel}
                      placeholder={`Cari ${entityLabel.toLowerCase()}…`}
                      ariaLabel={`${entityLabel} kedua pertandingan ${order + 1}`}
                      className="flex-1"
                    />
                  </div>
                  {/* Optional overrides. Left blank, the slot allocator times
                      this tie like any other. */}
                  <div className="flex flex-wrap items-center gap-2 pl-8">
                    <DateTimePicker
                      value={toEventInput(pair.scheduled_at ?? null, tz)}
                      onChange={(v) => setField(order, "scheduled_at", fromEventInput(v, tz))}
                      datePlaceholder="Jadwal"
                      clearable
                      className="min-w-[13rem] flex-1"
                    />
                    <div className="min-w-[8rem] flex-1">
                      <CourtSelect
                        value={pair.venue ?? null}
                        onChange={(v) => setField(order, "venue", v)}
                        courts={courts}
                        className="h-10 text-sm"
                        placeholder="Lapangan"
                        ariaLabel={`Lapangan pertandingan ${order + 1}`}
                      />
                    </div>
                  </div>
                </div>
              );
            })}
          </div>

          {unplaced.length > 0 && (
            <p className={dialogConsequences.danger}>
              {unplaced.length} {entityLabel.toLowerCase()} belum ditempatkan:{" "}
              {unplaced.map((t) => t.name).join(", ")}. Semua harus punya slot sebelum disimpan.
            </p>
          )}

          <p className="text-xs text-muted-foreground">
            Slot yang dikosongkan tetap kosong — isi belakangan lewat editor slot di bracket.
            Jadwal &amp; lapangan yang dibiarkan kosong ditata otomatis (jam {tzLabel(tz)}).
          </p>
        </div>
      )}
    </div>
  );
}
