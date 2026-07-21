"use client";

import { type ComponentProps, useState } from "react";
import { Shield } from "lucide-react";

import type { RegisterTeamPayload } from "@/lib/api/events";
import type { EventCategory, Team } from "@/types/api";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { rupiah } from "@/lib/labels";
import { nameInput } from "@/lib/name";
import { phoneInput } from "@/lib/phone";
import { ImageUploadField } from "@/components/shared/image-upload-field";
import { RosterEditor, emptyPlayer, fixedRoster, type PlayerRow } from "@/components/team/roster-editor";
import { participantLabel } from "@/lib/scoring";

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
  const [logoUrl, setLogoUrl] = useState<string>(team?.logo_url ?? "");
  // Blocks Save while the logo is still uploading, so the team isn't written
  // without the URL the upload is about to produce.
  const [logoUploading, setLogoUploading] = useState(false);
  const [categoryId, setCategoryId] = useState<string>(team?.category_id ?? "");
  const resolvedCategoryId = categoryId || categories[0]?.id || "";
  const category = categories.find((c) => c.id === resolvedCategoryId);
  // Tunggal/ganda: the entry *is* its players, so it has no name or crest of its
  // own — the backend derives one from the roster.
  const rosterSize = category?.roster_size ?? null;
  const isFixed = typeof rosterSize === "number";
  const [players, setPlayers] = useState<PlayerRow[]>(() =>
    team?.players?.length
      ? team.players.map((p) => ({
          id: p.id,
          full_name: p.full_name,
          jersey_number: p.jersey_number ?? "",
          position: p.position ?? "",
          photo_url: p.photo_url ?? null,
        }))
      : [emptyPlayer()]
  );

  if (!open) return null;

  const roster = isFixed ? fixedRoster(players, rosterSize) : players;
  // Every slot filled is the same rule the backend enforces on a fixed roster.
  const rosterReady = isFixed ? roster.every((p) => p.full_name.trim()) : true;

  const submit = () =>
    onSubmit({
      category_id: resolvedCategoryId,
      // A placeholder for a fixed roster — the backend overwrites it with the
      // players' names, which is the only name such an entry has.
      name: isFixed ? roster.map((p) => p.full_name.trim()).join(" / ") : info.name,
      logo_url: isFixed ? null : logoUrl || null,
      // Optional here: an offline entry often arrives as a team name and nothing
      // else. Send null rather than "" so a cleared field actually clears.
      contact_name: info.contact_name.trim() || null,
      contact_phone: info.contact_phone.trim() || null,
      players: roster
        .filter((p) => p.full_name.trim())
        .map((p) => ({
          id: p.id,
          full_name: p.full_name,
          jersey_number: p.jersey_number,
          position: p.position,
          photo_url: p.photo_url,
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
          {/* grid-cols-1 is load-bearing below sm: an implicit `auto` track is
              sized by its widest child's min-content, so the roster row used to
              stretch the form past the dialog and scroll it sideways. */}
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
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
                      {c.name} · {participantLabel(c.participant_type)} —{" "}
                      {c.registration_fee > 0 ? rupiah(c.registration_fee) : "Gratis"}
                    </option>
                  ))}
                </Select>
              </div>
            )}
            {!isFixed && (
            <ImageUploadField
              className="sm:col-span-2"
              label="Logo tim"
              value={logoUrl}
              onChange={setLogoUrl}
              onBusyChange={setLogoUploading}
              folder="teams"
              maxDim={512}
              previewClassName="h-24 w-24"
              placeholder={<Shield className="h-7 w-7 text-muted-foreground" />}
              hint="Opsional. Maksimal 5 MB, otomatis dikonversi ke WebP. Bentuk persegi paling rapi."
            />
            )}
            {!isFixed && (
              <Field
                id="manual-name"
                label="Nama tim"
                required
                sanitize={nameInput}
                value={info.name}
                error={fieldErrors?.name}
                onChange={(v) => setInfo({ ...info, name: v })}
              />
            )}
            <Field
              id="manual-contact"
              label="Nama kontak"
              hint="opsional"
              value={info.contact_name}
              error={fieldErrors?.contact_name}
              onChange={(v) => setInfo({ ...info, contact_name: v })}
            />
            <Field
              id="manual-phone"
              label="No. HP kontak"
              hint="opsional"
              inputMode="tel"
              sanitize={phoneInput}
              value={info.contact_phone}
              error={fieldErrors?.contact_phone}
              onChange={(v) => setInfo({ ...info, contact_phone: v })}
            />
          </div>

          <div className="grid gap-2">
            <Label className="font-semibold">
              {isFixed ? (
                rosterSize === 1 ? (
                  "Nama peserta"
                ) : (
                  "Pasangan"
                )
              ) : (
                <>
                  Daftar pemain{" "}
                  <span className="font-normal text-muted-foreground">(opsional)</span>
                </>
              )}
            </Label>
            <RosterEditor
              players={roster}
              onChange={setPlayers}
              sport={sport}
              size={rosterSize}
            />
            {isFixed && (
              <p className="text-xs text-muted-foreground">
                Nama peserta diambil dari sini — mis. “Dimas / Ammar”.
              </p>
            )}
          </div>
        </div>

        <div className="flex items-center justify-end gap-2 border-t border-border p-4">
          <Button variant="ghost" onClick={onClose} disabled={pending}>
            Batal
          </Button>
          <Button
            onClick={submit}
            disabled={
              pending ||
              logoUploading ||
              !resolvedCategoryId ||
              (isFixed ? !rosterReady : !info.name.trim())
            }
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
  hint,
  error,
  inputMode,
  sanitize,
}: {
  id: string;
  label: string;
  value: string;
  onChange: (v: string) => void;
  required?: boolean;
  /** Muted note after the label, e.g. "opsional". */
  hint?: string;
  error?: string;
  inputMode?: ComponentProps<typeof Input>["inputMode"];
  // Runs on every keystroke to drop characters this field doesn't accept.
  sanitize?: (v: string) => string;
}) {
  return (
    <div className="grid gap-2">
      <Label htmlFor={id} className="font-semibold">
        {label}
        {required && <span className="text-[var(--danger)]"> *</span>}
        {hint && <span className="font-normal text-muted-foreground"> ({hint})</span>}
      </Label>
      <Input
        id={id}
        value={value}
        inputMode={inputMode}
        aria-invalid={!!error}
        onChange={(e) => onChange(sanitize ? sanitize(e.target.value) : e.target.value)}
      />
      {error && <p className="text-xs text-destructive">{error}</p>}
    </div>
  );
}
