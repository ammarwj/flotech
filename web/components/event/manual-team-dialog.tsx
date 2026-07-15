"use client";

import { useState } from "react";

import type { RegisterTeamPayload } from "@/lib/api/events";
import type { EventCategory, Team } from "@/types/api";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { rupiah } from "@/lib/labels";
import { RosterEditor, emptyPlayer, type PlayerRow } from "@/components/team/roster-editor";

/**
 * Enter (or fix) a team the organizer took in offline — over WhatsApp, on paper,
 * or paid in cash at the venue. Not every tournament is filled through the app,
 * and a team that isn't in the system can't be drawn into a schedule.
 *
 * A team added here is approved on the spot and settled outside the platform, so
 * it never enters the approval queue and no money is credited to the wallet.
 */
export function ManualTeamDialog({
  open,
  team,
  categories,
  sport,
  pending,
  fieldErrors,
  onClose,
  onSubmit,
}: {
  open: boolean;
  /** Editing an existing team, or null to add a new one. */
  team?: Team | null;
  /** The event's competition categories the team can be entered in. */
  categories: EventCategory[];
  sport?: string | null;
  pending?: boolean;
  fieldErrors?: Record<string, string>;
  onClose: () => void;
  onSubmit: (payload: RegisterTeamPayload) => void;
}) {
  // Seeded once, on mount. The caller gives this component a `key` per team (and
  // per open), so a fresh dialog is mounted each time instead of an effect
  // resetting a stale one — no chance of showing the previous team's roster.
  const [info, setInfo] = useState({
    name: team?.name ?? "",
    contact_name: team?.contact_name ?? "",
    contact_phone: team?.contact_phone ?? "",
  });
  const [categoryId, setCategoryId] = useState<string>(team?.category_id ?? "");
  const resolvedCategoryId = categoryId || categories[0]?.id || "";
  const [players, setPlayers] = useState<PlayerRow[]>(() =>
    team?.players?.length
      ? team.players.map((p) => ({
          id: p.id,
          full_name: p.full_name,
          jersey_number: p.jersey_number ?? "",
          position: p.position ?? "",
        }))
      : [emptyPlayer()]
  );

  if (!open) return null;

  const submit = () =>
    onSubmit({
      category_id: resolvedCategoryId,
      ...info,
      players: players
        .filter((p) => p.full_name.trim())
        .map((p) => ({
          id: p.id,
          full_name: p.full_name,
          jersey_number: p.jersey_number,
          position: p.position,
        })),
    });

  return (
    <div
      className="fixed inset-0 z-50 grid place-items-center bg-black/50 p-4"
      role="dialog"
      aria-modal="true"
      aria-label={team ? "Ubah tim" : "Tambah tim manual"}
    >
      <div className="max-h-[85vh] w-full max-w-2xl overflow-y-auto rounded-xl border border-border bg-[var(--surface)] shadow-[var(--shadow-lg)]">
        <div className="border-b border-border p-4">
          <h2 className="text-base font-bold" style={{ fontFamily: "var(--font-display)" }}>
            {team ? "Ubah data tim" : "Tambah tim manual"}
          </h2>
          <p className="mt-1 text-sm text-muted-foreground">
            {team
              ? "Perbaiki data tim dan roster-nya."
              : "Untuk tim yang mendaftar di luar aplikasi. Tim langsung disetujui dan dianggap lunas — pembayarannya diurus di luar platform."}
          </p>
        </div>

        <div className="grid gap-4 p-4">
          <div className="grid gap-4 sm:grid-cols-2">
            {categories.length > 0 && (
              <div className="grid gap-2 sm:col-span-2">
                <Label htmlFor="manual-category" className="font-semibold">
                  Kategori<span className="text-[var(--danger)]"> *</span>
                </Label>
                <Select
                  id="manual-category"
                  value={resolvedCategoryId}
                  onChange={(e) => setCategoryId(e.target.value)}
                >
                  {categories.map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.name} — {c.registration_fee > 0 ? rupiah(c.registration_fee) : "Gratis"}
                    </option>
                  ))}
                </Select>
              </div>
            )}
            <Field
              id="manual-name"
              label="Nama tim"
              required
              value={info.name}
              error={fieldErrors?.name}
              onChange={(v) => setInfo({ ...info, name: v })}
            />
            <Field
              id="manual-contact"
              label="Nama kontak"
              required
              value={info.contact_name}
              error={fieldErrors?.contact_name}
              onChange={(v) => setInfo({ ...info, contact_name: v })}
            />
            <Field
              id="manual-phone"
              label="No. HP kontak"
              required
              value={info.contact_phone}
              error={fieldErrors?.contact_phone}
              onChange={(v) => setInfo({ ...info, contact_phone: v })}
            />
          </div>

          <div className="grid gap-2">
            <Label className="font-semibold">
              Daftar pemain <span className="font-normal text-muted-foreground">(opsional)</span>
            </Label>
            <RosterEditor players={players} onChange={setPlayers} sport={sport} />
          </div>
        </div>

        <div className="flex items-center justify-end gap-2 border-t border-border p-4">
          <Button variant="ghost" onClick={onClose} disabled={pending}>
            Batal
          </Button>
          <Button
            onClick={submit}
            disabled={pending || !resolvedCategoryId || !info.name.trim() || !info.contact_name.trim()}
          >
            {pending ? "Menyimpan…" : team ? "Simpan perubahan" : "Tambah tim"}
          </Button>
        </div>
      </div>
    </div>
  );
}

function Field({
  id,
  label,
  value,
  onChange,
  required,
  error,
}: {
  id: string;
  label: string;
  value: string;
  onChange: (v: string) => void;
  required?: boolean;
  error?: string;
}) {
  return (
    <div className="grid gap-2">
      <Label htmlFor={id} className="font-semibold">
        {label}
        {required && <span className="text-[var(--danger)]"> *</span>}
      </Label>
      <Input id={id} value={value} aria-invalid={!!error} onChange={(e) => onChange(e.target.value)} />
      {error && <p className="text-xs text-destructive">{error}</p>}
    </div>
  );
}
