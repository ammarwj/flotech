"use client";

import { Shirt, UserCog } from "lucide-react";

import { cn } from "@/lib/utils";
import { useCatalog } from "@/lib/hooks/use-catalog";
import type { LineupRosterOfficial, LineupRosterPlayer } from "@/types/api";

/**
 * The manager's team sheet: who starts, who is on the bench, and which officials
 * sit with them.
 *
 * Every player of the roster is listed once, and the three-way control on the
 * right is the whole interaction — there is no "add" and no separate pool to
 * drag out of. That is deliberate: the sheet may only name players of this team,
 * the server refuses anyone else, and a picker that could offer a name it will
 * then reject is a picker that teaches the manager to distrust it.
 *
 * "Tidak dibawa" is a real third state, not the absence of a choice. The
 * full-list contract means a player left out of the payload is deleted from the
 * sheet, so the row has to be able to say so out loud — otherwise a manager
 * removing somebody would be doing it by failing to click anything.
 */

/** What the sheet says about one player. `null` = not on it at all. */
export type LineupRole = "starter" | "substitute" | null;

/** Player id → their place on the sheet. Officials are a plain id list. */
export type LineupSelection = Record<string, LineupRole>;

const ROLE_OPTIONS: { value: LineupRole; label: string }[] = [
  { value: "starter", label: "Inti" },
  { value: "substitute", label: "Cadangan" },
  { value: null, label: "Tidak dibawa" },
];

export function countRole(selection: LineupSelection, role: LineupRole): number {
  return Object.values(selection).filter((r) => r === role).length;
}

export function LineupEditor({
  roster,
  officials,
  selection,
  chosenOfficials,
  onSelectionChange,
  onOfficialsChange,
  sport,
  disabled = false,
}: {
  roster: LineupRosterPlayer[];
  officials: LineupRosterOfficial[];
  selection: LineupSelection;
  /** Ids of the officials named on the sheet, in the order they will print. */
  chosenOfficials: string[];
  onSelectionChange: (next: LineupSelection) => void;
  onOfficialsChange: (next: string[]) => void;
  sport?: string | null;
  disabled?: boolean;
}) {
  const { officialRoleLabel } = useCatalog();

  const setRole = (playerId: string, role: LineupRole) => {
    onSelectionChange({ ...selection, [playerId]: role });
  };

  const toggleOfficial = (officialId: string) => {
    onOfficialsChange(
      chosenOfficials.includes(officialId)
        ? chosenOfficials.filter((id) => id !== officialId)
        : [...chosenOfficials, officialId],
    );
  };

  return (
    <div className="grid gap-6">
      <section className="grid gap-2">
        <div className="flex flex-wrap items-baseline justify-between gap-2">
          <h3 className="inline-flex items-center gap-2 font-semibold">
            <Shirt className="h-4 w-4" /> Pemain
          </h3>
          <p className="text-sm text-muted-foreground">
            {countRole(selection, "starter")} inti ·{" "}
            {countRole(selection, "substitute")} cadangan
          </p>
        </div>

        {roster.length === 0 && (
          <p className="rounded-md border border-border bg-[var(--bg-soft)] px-4 py-3 text-sm text-muted-foreground">
            Roster tim masih kosong. Lengkapi dulu daftar pemain di halaman tim.
          </p>
        )}

        <ul className="grid gap-2">
          {roster.map((player) => (
            <li
              key={player.id}
              className="flex flex-wrap items-center gap-3 rounded-xl border border-border p-3"
            >
              <span
                className="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-[var(--tint)] text-sm font-bold text-[var(--brand-600)]"
                style={{ fontFamily: "var(--font-display)" }}
              >
                {player.jersey_number || "–"}
              </span>
              <div className="min-w-0 flex-1">
                <p className="truncate font-medium">{player.full_name}</p>
                {player.position && (
                  <p className="text-sm text-muted-foreground">{player.position}</p>
                )}
              </div>
              <RoleToggle
                value={selection[player.id] ?? null}
                onChange={(role) => setRole(player.id, role)}
                disabled={disabled}
                name={player.full_name}
              />
            </li>
          ))}
        </ul>
      </section>

      <section className="grid gap-2">
        <h3 className="inline-flex items-center gap-2 font-semibold">
          <UserCog className="h-4 w-4" /> Ofisial di bangku
        </h3>

        {officials.length === 0 ? (
          <p className="rounded-md border border-border bg-[var(--bg-soft)] px-4 py-3 text-sm text-muted-foreground">
            Belum ada pelatih atau ofisial terdaftar. Opsional.
          </p>
        ) : (
          <ul className="grid gap-2">
            {officials.map((official) => {
              const chosen = chosenOfficials.includes(official.id);

              return (
                <li key={official.id}>
                  <button
                    type="button"
                    onClick={() => toggleOfficial(official.id)}
                    disabled={disabled}
                    aria-pressed={chosen}
                    className={cn(
                      "flex w-full items-center gap-3 rounded-xl border p-3 text-left transition-colors",
                      chosen
                        ? "border-[var(--brand-600)] bg-[var(--tint)]"
                        : "border-border hover:bg-[var(--bg-soft)]",
                      disabled && "cursor-not-allowed opacity-60",
                    )}
                  >
                    <div className="min-w-0 flex-1">
                      <p className="truncate font-medium">{official.full_name}</p>
                      <p className="text-sm text-muted-foreground">
                        {/* Through the catalogue, never hardcoded: an admin may
                            rename a role and every screen has to follow. */}
                        {officialRoleLabel(sport, official.role) || "Tanpa jabatan"}
                      </p>
                    </div>
                    <span className="shrink-0 text-sm font-medium text-muted-foreground">
                      {chosen ? "Dibawa" : "Tidak"}
                    </span>
                  </button>
                </li>
              );
            })}
          </ul>
        )}
      </section>
    </div>
  );
}

/** Three buttons, one of them always on — see the note about the third state. */
function RoleToggle({
  value,
  onChange,
  disabled,
  name,
}: {
  value: LineupRole;
  onChange: (role: LineupRole) => void;
  disabled: boolean;
  name: string;
}) {
  return (
    <div
      role="group"
      aria-label={`Status ${name}`}
      className="inline-flex shrink-0 overflow-hidden rounded-lg border border-border"
    >
      {ROLE_OPTIONS.map((option) => {
        const active = value === option.value;

        return (
          <button
            key={option.label}
            type="button"
            onClick={() => onChange(option.value)}
            disabled={disabled}
            aria-pressed={active}
            className={cn(
              "px-3 py-1.5 text-sm font-medium transition-colors",
              active
                ? "bg-[var(--brand-600)] text-white"
                : "text-muted-foreground hover:bg-[var(--bg-soft)]",
              disabled && "cursor-not-allowed opacity-60",
            )}
          >
            {option.label}
          </button>
        );
      })}
    </div>
  );
}
