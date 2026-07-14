"use client";

import { Plus, X } from "lucide-react";

import { useCatalog } from "@/lib/hooks/use-catalog";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";

export type PlayerRow = {
  id?: string;
  full_name: string;
  jersey_number: string;
  position: string;
};

export const emptyPlayer = (): PlayerRow => ({ full_name: "", jersey_number: "", position: "" });

/**
 * The roster table, shared by the three places a squad gets typed in: public
 * registration, the participant's own team page, and the organizer entering a
 * team by hand. Same columns everywhere — name, number, position — so a roster
 * doesn't lose a field depending on who filled it.
 */
export function RosterEditor({
  players,
  onChange,
  sport,
  disabled,
}: {
  players: PlayerRow[];
  onChange: (players: PlayerRow[]) => void;
  /** Sport slug — decides which positions may be picked. */
  sport?: string | null;
  disabled?: boolean;
}) {
  const { positionsFor } = useCatalog();

  // The admin defines these per sport (sport_positions). A sport with none has
  // nothing to offer, and the API rejects any position on its rosters — so the
  // column disappears rather than showing an empty dropdown.
  const positions = positionsFor(sport);

  const set = (i: number, patch: Partial<PlayerRow>) =>
    onChange(players.map((p, j) => (j === i ? { ...p, ...patch } : p)));

  return (
    <div className="grid gap-2">
      {players.map((p, i) => (
        <div key={p.id ?? `new-${i}`} className="flex items-center gap-2">
          <span className="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-[var(--bg-soft)] text-xs font-semibold text-muted-foreground">
            {i + 1}
          </span>
          <Input
            placeholder="Nama pemain"
            aria-label={`Nama pemain ${i + 1}`}
            value={p.full_name}
            disabled={disabled}
            onChange={(e) => set(i, { full_name: e.target.value })}
          />
          <Input
            className="w-20 shrink-0"
            placeholder="No."
            aria-label={`Nomor punggung pemain ${i + 1}`}
            value={p.jersey_number}
            disabled={disabled}
            onChange={(e) => set(i, { jersey_number: e.target.value })}
          />
          {positions.length > 0 && (
            <Select
              className="w-36 shrink-0"
              aria-label={`Posisi pemain ${i + 1}`}
              value={p.position}
              disabled={disabled}
              onChange={(e) => set(i, { position: e.target.value })}
            >
              <option value="">Posisi</option>
              {positions.map((pos) => (
                <option key={pos.key} value={pos.key}>
                  {pos.label}
                </option>
              ))}
            </Select>
          )}
          {!disabled && (
            <Button
              type="button"
              size="icon"
              variant="ghost"
              className="shrink-0 text-muted-foreground"
              aria-label={`Hapus pemain ${i + 1}`}
              onClick={() => onChange(players.filter((_, j) => j !== i))}
            >
              <X className="h-4 w-4" />
            </Button>
          )}
        </div>
      ))}

      {!disabled && (
        <Button
          type="button"
          size="sm"
          variant="outline"
          className="justify-self-start"
          onClick={() => onChange([...players, emptyPlayer()])}
        >
          <Plus className="h-4 w-4" />
          Pemain
        </Button>
      )}
    </div>
  );
}
