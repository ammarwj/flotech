"use client";

import { useEffect, useState } from "react";
import { Shuffle, X } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { useCatalog } from "@/lib/hooks/use-catalog";
import { groupNames, type HybridConfig } from "@/lib/hybrid";
import type { DrawPayload } from "@/lib/api/matches";
import type { DrawMethod, Team } from "@/types/api";

/**
 * Draws the approved teams into groups. Random deals them out evenly, pot deals
 * pot-by-pot so the seeds spread across groups, and manual lets the organizer
 * place every team by hand.
 */
interface DrawDialogProps {
  teams: Team[];
  config: HybridConfig;
  hasMatches: boolean;
  pending: boolean;
  onClose: () => void;
  onSubmit: (payload: DrawPayload) => void;
}

export function GroupDrawDialog({ open, ...props }: DrawDialogProps & { open: boolean }) {
  // Mounted only while open, so the editors start from the current draw every time.
  return open ? <DrawDialog {...props} /> : null;
}

function DrawDialog({ teams, config, hasMatches, pending, onClose, onSubmit }: DrawDialogProps) {
  const { draw_methods } = useCatalog();
  const [method, setMethod] = useState<DrawMethod>(config.draw_method);
  const [assignments, setAssignments] = useState<Record<string, string>>(() =>
    Object.fromEntries(teams.filter((t) => t.group_name).map((t) => [t.id, t.group_name!]))
  );
  const [pots, setPots] = useState<Record<string, number>>(() =>
    Object.fromEntries(teams.filter((t) => t.seed_pot).map((t) => [t.id, t.seed_pot!]))
  );

  const groups = groupNames(config);

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => e.key === "Escape" && onClose();
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [onClose]);

  const perGroup = Math.ceil(teams.length / Math.max(1, groups.length));

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" onClick={onClose}>
      <div
        role="dialog"
        aria-modal="true"
        onClick={(e) => e.stopPropagation()}
        className="flex max-h-[85vh] w-full max-w-lg flex-col overflow-hidden rounded-xl border border-border bg-card shadow-[var(--shadow-lg)]"
      >
        <div className="flex items-start gap-3 border-b border-border p-5">
          <span className="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-[var(--tint)] text-[var(--brand-600)]">
            <Shuffle className="h-5 w-5" />
          </span>
          <div className="min-w-0 flex-1">
            <h2 className="text-base font-bold" style={{ fontFamily: "var(--font-display)" }}>
              Undian Grup
            </h2>
            <p className="mt-0.5 text-sm text-muted-foreground">
              {teams.length} tim disetujui · {groups.length} grup (± {perGroup} tim per grup).
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
          <div className="grid gap-1.5">
            <Label className="font-semibold">Metode</Label>
            <Select value={method} onChange={(e) => setMethod(e.target.value as DrawMethod)}>
              {draw_methods.map((m) => (
                <option key={m.key} value={m.key}>
                  {m.label}
                </option>
              ))}
            </Select>
            <p className="text-xs text-muted-foreground">
              {method === "random" && "Tim dibagi acak dan merata ke seluruh grup."}
              {method === "pot" && "Tiap grup mengambil satu tim dari pot 1, lalu pot 2, dan seterusnya."}
              {method === "manual" && "Tentukan sendiri grup untuk setiap tim."}
            </p>
          </div>

          {method !== "random" && teams.length > 0 && (
            <div className="grid gap-2">
              <Label className="font-semibold">
                {method === "pot" ? "Pot tiap tim" : "Grup tiap tim"}
              </Label>
              <div className="grid gap-1.5 rounded-lg border border-border p-2">
                {teams.map((t) => (
                  <div key={t.id} className="flex items-center gap-3">
                    <span className="min-w-0 flex-1 truncate text-sm font-medium">{t.name}</span>
                    {method === "pot" ? (
                      <Input
                        type="number"
                        min={1}
                        max={16}
                        value={pots[t.id] ?? ""}
                        placeholder="Pot"
                        onChange={(e) =>
                          setPots((p) => ({ ...p, [t.id]: Math.max(1, Number(e.target.value) || 1) }))
                        }
                        className="h-9 w-20"
                        aria-label={`Pot ${t.name}`}
                      />
                    ) : (
                      <Select
                        value={assignments[t.id] ?? ""}
                        onChange={(e) => setAssignments((a) => ({ ...a, [t.id]: e.target.value }))}
                        className="h-9 w-28"
                        aria-label={`Grup ${t.name}`}
                      >
                        <option value="">—</option>
                        {groups.map((g) => (
                          <option key={g} value={g}>
                            Grup {g}
                          </option>
                        ))}
                      </Select>
                    )}
                  </div>
                ))}
              </div>
              {method === "manual" && (
                <p className="text-xs text-muted-foreground">
                  Tim tanpa grup akan ditaruh otomatis di grup yang paling sedikit isinya.
                </p>
              )}
            </div>
          )}

          {hasMatches && (
            <p className="rounded-md border border-[color-mix(in_srgb,var(--warning)_40%,transparent)] bg-[color-mix(in_srgb,var(--warning)_8%,transparent)] px-3 py-2 text-xs text-[var(--warning)]">
              Undian ulang mengubah isi grup — buat ulang jadwal setelahnya agar cocok.
            </p>
          )}
        </div>

        <div className="flex items-center justify-end gap-2 border-t border-border p-4">
          <Button variant="ghost" onClick={onClose} disabled={pending}>
            Batal
          </Button>
          <Button
            onClick={() => onSubmit({ method, assignments, pots })}
            disabled={pending || teams.length === 0}
          >
            <Shuffle className="h-4 w-4" />
            {pending ? "Mengundi…" : "Undi Grup"}
          </Button>
        </div>
      </div>
    </div>
  );
}
