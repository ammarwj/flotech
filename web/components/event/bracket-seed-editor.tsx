"use client";

import { dialogConsequences } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { unplacedTeams } from "@/lib/bracket";
import { fromEventInput, toEventInput, tzLabel } from "@/lib/match-dates";
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
  size,
  pool,
  mode,
  value,
  tz,
  onModeChange,
  onChange,
  autoHint,
}: {
  /** Bracket size; the editor draws size / 2 ties. */
  size: number;
  pool: SeedTeam[];
  mode: SeedingMode;
  value: SeedPair[];
  /** The venue's zone: kickoffs are typed and read as its wall clock. */
  tz: string;
  onModeChange: (mode: SeedingMode) => void;
  onChange: (pairs: SeedPair[]) => void;
  /** What automatic seeding does here, since it differs per engine. */
  autoHint: string;
}) {
  const slots = Math.max(1, Math.floor(size / 2));
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

  const setSide = (order: number, side: "home_team_id" | "away_team_id", id: string) =>
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
              const options = (opposite: string | null) =>
                pool.map((t) => (
                  <option key={t.id} value={t.id} disabled={taken.has(t.id) || t.id === opposite}>
                    {t.name}
                  </option>
                ));

              return (
                <div key={order} className="grid gap-1.5 border-b border-border pb-2 last:border-0 last:pb-0">
                  <div className="flex items-center gap-2">
                    <span className="w-6 shrink-0 text-xs font-semibold text-muted-foreground">
                      {order + 1}.
                    </span>
                    <Select
                      value={pair.home_team_id ?? ""}
                      onChange={(e) => setSide(order, "home_team_id", e.target.value)}
                      className="h-9 min-w-0 flex-1"
                      aria-label={`Tim pertama pertandingan ${order + 1}`}
                    >
                      <option value="">—</option>
                      {options(pair.away_team_id)}
                    </Select>
                    <span className="shrink-0 text-xs text-muted-foreground">vs</span>
                    <Select
                      value={pair.away_team_id ?? ""}
                      onChange={(e) => setSide(order, "away_team_id", e.target.value)}
                      className="h-9 min-w-0 flex-1"
                      aria-label={`Tim kedua pertandingan ${order + 1}`}
                    >
                      <option value="">Bye</option>
                      {options(pair.home_team_id)}
                    </Select>
                  </div>
                  {/* Optional overrides. Left blank, the slot allocator times
                      this tie like any other. */}
                  <div className="flex items-center gap-2 pl-8">
                    <Input
                      type="datetime-local"
                      value={toEventInput(pair.scheduled_at ?? null, tz)}
                      onChange={(e) =>
                        setField(order, "scheduled_at", fromEventInput(e.target.value, tz))
                      }
                      className="h-8 min-w-0 flex-1 text-xs"
                      aria-label={`Jadwal pertandingan ${order + 1}`}
                    />
                    <Input
                      value={pair.venue ?? ""}
                      onChange={(e) => setField(order, "venue", e.target.value || null)}
                      placeholder="Lapangan"
                      className="h-8 min-w-0 flex-1 text-xs"
                      aria-label={`Lapangan pertandingan ${order + 1}`}
                    />
                  </div>
                </div>
              );
            })}
          </div>

          {unplaced.length > 0 && (
            <p className={dialogConsequences.danger}>
              {unplaced.length} tim belum ditempatkan: {unplaced.map((t) => t.name).join(", ")}.
              Semua tim harus punya slot sebelum bracket dibuat.
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
