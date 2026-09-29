"use client";

import { Shirt, UserCog } from "lucide-react";

import { Badge } from "@/components/ui/badge";
import { cn } from "@/lib/utils";
import { useCatalog } from "@/lib/hooks/use-catalog";
import { banReasonLabel } from "@/lib/scoring";
import type {
  DisciplineRules,
  LineupBan,
  LineupRosterOfficial,
  LineupRosterPlayer,
  SquadRules,
} from "@/types/api";

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
 *
 * The squad size is a ceiling here and nothing else. A manager who has named
 * eleven cannot name a twelfth — the button is simply off, the same shape a
 * suspension takes — but nobody is pushed towards eleven: a half-filled draft is
 * a legitimate save, and the exactly-eleven rule belongs to the page's submit
 * button, which is the moment it becomes true. Two operators, one number; see
 * `SquadRules` on the server.
 *
 * A suspended player stays on the list, struck through with the reason beside
 * them, rather than disappearing from it. Removing the row would leave the
 * manager looking for a player who is simply gone and no explanation of why the
 * squad shrank; the server refuses the name either way, and this is the half of
 * that refusal they can read before pressing anything.
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
  bans,
  rules,
  squadRules,
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
  /**
   * Who may not be fielded here. Required rather than defaulting to `[]`, for
   * the reason already written for `PublicMatchCard`: a default would let a
   * caller that forgot to pass them render an editor that blocks nobody, and
   * pass review looking identical to one that works.
   */
  bans: LineupBan[];
  /** The rules naming the reason; `null` for a sport without cards. */
  rules: DisciplineRules | null;
  /**
   * How many the sheet may name. Required for the same reason `bans` is: a
   * default would let a caller that forgot it render an editor with no ceiling
   * at all, which looks identical to one that works until somebody names a
   * twelfth. `null` is the sport's own answer — a set sport has no starting
   * eleven, so the counter says nothing rather than inventing a number.
   */
  squadRules: SquadRules | null;
  disabled?: boolean;
}) {
  const { officialRoleLabel, sport: sportDef } = useCatalog();

  // By player, so a row asks once rather than scanning the list per render.
  const bannedBy = new Map(bans.map((ban) => [ban.player_id, ban]));

  const starters = countRole(selection, "starter");
  const substitutes = countRole(selection, "substitute");
  // Full means "this row cannot move into that column", so a player already in
  // it is never blocked — without that exception the eleventh starter could not
  // be sent to the bench, and the sheet would be stuck at its own ceiling.
  const startersFull = squadRules ? starters >= squadRules.starters : false;
  const benchFull = squadRules ? substitutes >= squadRules.max_substitutes : false;

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
          {/* The ceiling is shown next to the count rather than only enforced,
              so a manager who finds a button off can see why. */}
          <p className="text-sm text-muted-foreground">
            {starters}
            {squadRules && `/${squadRules.starters}`} inti · {substitutes}
            {squadRules && `/${squadRules.max_substitutes}`} cadangan
          </p>
        </div>

        {roster.length === 0 && (
          <p className="rounded-md border border-border bg-[var(--bg-soft)] px-4 py-3 text-sm text-muted-foreground">
            Roster tim masih kosong. Lengkapi dulu daftar pemain di halaman tim.
          </p>
        )}

        <ul className="grid gap-2">
          {roster.map((player) => {
            const ban = bannedBy.get(player.id);

            return (
              <li
                key={player.id}
                className={cn(
                  "flex flex-wrap items-center gap-3 rounded-xl border p-3",
                  ban
                    ? "border-[color-mix(in_srgb,var(--danger)_40%,transparent)] bg-[color-mix(in_srgb,var(--danger)_6%,transparent)]"
                    : "border-border",
                )}
              >
                <span
                  className={cn(
                    "grid h-9 w-9 shrink-0 place-items-center rounded-lg text-sm font-bold",
                    ban
                      ? "bg-[color-mix(in_srgb,var(--danger)_12%,transparent)] text-[var(--danger)]"
                      : "bg-[var(--tint)] text-[var(--brand-600)]",
                  )}
                  style={{ fontFamily: "var(--font-display)" }}
                >
                  {player.jersey_number || "–"}
                </span>
                <div className="min-w-0 flex-1">
                  <p
                    className={cn(
                      "truncate font-medium",
                      ban && "text-muted-foreground line-through",
                    )}
                  >
                    {player.full_name}
                  </p>
                  {ban ? (
                    <p className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted-foreground">
                      <Badge variant="danger" dot>
                        Larangan bermain
                      </Badge>
                      {/* Through the catalogue, never hardcoded: "kartu merah"
                          and the threshold are the sport's own, and an admin may
                          change either without a deploy. */}
                      <span>{banReasonLabel(ban.reason, sportDef(sport), rules)}</span>
                      {ban.bans_remaining > 1 && <span>· sisa {ban.bans_remaining} laga</span>}
                    </p>
                  ) : (
                    player.position && (
                      <p className="text-sm text-muted-foreground">{player.position}</p>
                    )
                  )}
                </div>
                <RoleToggle
                  value={ban ? null : (selection[player.id] ?? null)}
                  onChange={(role) => setRole(player.id, role)}
                  disabled={disabled || Boolean(ban)}
                  full={{
                    starter: startersFull,
                    substitute: benchFull,
                  }}
                  name={player.full_name}
                />
              </li>
            );
          })}
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
  full,
  name,
}: {
  value: LineupRole;
  onChange: (role: LineupRole) => void;
  disabled: boolean;
  /** Which columns are at their ceiling. The row's own column never counts. */
  full: { starter: boolean; substitute: boolean };
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
        // "Tidak dibawa" is never blocked: taking somebody off the sheet is how
        // a full column gets room, and the third state is a real choice.
        const blocked =
          !active && option.value !== null && full[option.value];

        return (
          <button
            key={option.label}
            type="button"
            onClick={() => onChange(option.value)}
            disabled={disabled || blocked}
            aria-pressed={active}
            className={cn(
              "px-3 py-1.5 text-sm font-medium transition-colors",
              active
                ? "bg-[var(--brand-600)] text-white"
                : "text-muted-foreground hover:bg-[var(--bg-soft)]",
              (disabled || blocked) && "cursor-not-allowed opacity-60",
            )}
          >
            {option.label}
          </button>
        );
      })}
    </div>
  );
}
