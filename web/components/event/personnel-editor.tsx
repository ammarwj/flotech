"use client";

import { ImagePlus, Loader2, Plus, User, X } from "lucide-react";
import { toast } from "sonner";

import { compressToWebp } from "@/lib/image";
import { nameInput } from "@/lib/name";
import { uploadImage } from "@/lib/api/events";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import type { EventPersonnelKind } from "@/types/api";

export type PersonnelRow = {
  id?: string;
  full_name: string;
  kind: EventPersonnelKind;
  /** Free text — "Wasit Utama", "Panitia Lapangan". "" means "no title". */
  role_label: string;
  photo_url?: string | null;
  /** Render-only: local blob for instant preview. */
  photo_preview?: string;
  /** Render-only: upload in flight. */
  photo_uploading?: boolean;
};

export const emptyPersonnel = (kind: EventPersonnelKind = "wasit"): PersonnelRow => ({
  full_name: "",
  kind,
  role_label: "",
});

const KIND_OPTIONS: { value: EventPersonnelKind; label: string }[] = [
  { value: "wasit", label: "Wasit" },
  { value: "staf", label: "Staf" },
];

/** The photo to render for a row: local blob first, else a stored http(s) URL. */
function photoShown(p: PersonnelRow): string | null {
  return (
    p.photo_preview ??
    (p.photo_url && /^https?:\/\//.test(p.photo_url) ? p.photo_url : null)
  );
}

/**
 * Referees and match staff — the people an event needs who belong to no team.
 *
 * Shaped after OfficialEditor, with one difference that is not cosmetic: the
 * role is a free-text Input, not a Select over a catalogue. A bench role is a
 * property of the sport; "Panitia Lapangan" is what this particular committee
 * decided to call its people, and no master list would be right for the next
 * event. Left empty, the card falls back to the kind's label.
 */
export function PersonnelEditor({
  personnel,
  onChange,
  disabled,
}: {
  personnel: PersonnelRow[];
  onChange: (personnel: PersonnelRow[]) => void;
  disabled?: boolean;
}) {
  const set = (i: number, patch: Partial<PersonnelRow>) =>
    onChange(personnel.map((p, j) => (j === i ? { ...p, ...patch } : p)));

  const uploadPhoto = async (i: number, file?: File | null) => {
    if (!file) return;
    if (!file.type.startsWith("image/")) {
      toast.error("Foto petugas harus berupa gambar.");
      return;
    }
    if (file.size > 2 * 1024 * 1024) {
      toast.error("Ukuran foto maksimal 2 MB.");
      return;
    }
    try {
      const webp = await compressToWebp(file, { maxDim: 512, quality: 0.85 });
      set(i, {
        photo_preview: URL.createObjectURL(webp),
        photo_uploading: true,
      });
      const url = await uploadImage(webp, "personnel");
      set(i, { photo_url: url, photo_uploading: false });
    } catch {
      toast.error("Gagal mengunggah foto petugas.");
      set(i, { photo_preview: undefined, photo_uploading: false });
    }
  };

  return (
    <div className="grid gap-2">
      {personnel.map((p, i) => {
        const shown = photoShown(p);
        // items-start: the captioned photo column is taller than the controls
        // beside it, same as RosterEditor and OfficialEditor.
        return (
          <div
            key={p.id ?? `new-${i}`}
            className="flex flex-wrap items-start gap-2"
          >
            <div className="flex shrink-0 flex-col items-center gap-1">
              <div className="relative h-10 w-10">
                <label
                  className={`grid h-10 w-10 place-items-center overflow-hidden rounded-md border border-border bg-[var(--bg-soft)] text-muted-foreground ${
                    disabled
                      ? ""
                      : "cursor-pointer hover:border-[var(--brand-500)] hover:text-foreground"
                  }`}
                  aria-label={`Foto petugas ${i + 1}`}
                  title={
                    disabled
                      ? undefined
                      : "Unggah foto petugas (opsional, maks 2 MB)"
                  }
                >
                  {p.photo_uploading ? (
                    <Loader2 className="h-4 w-4 animate-spin" />
                  ) : shown ? (
                    // eslint-disable-next-line @next/next/no-img-element
                    <img
                      src={shown}
                      alt={p.full_name || `Petugas ${i + 1}`}
                      className="h-full w-full object-cover"
                    />
                  ) : disabled ? (
                    <User className="h-4 w-4" />
                  ) : (
                    <ImagePlus className="h-4 w-4" />
                  )}
                  {!disabled && (
                    <input
                      type="file"
                      accept="image/*"
                      className="hidden"
                      disabled={p.photo_uploading}
                      onChange={(e) => {
                        uploadPhoto(i, e.target.files?.[0]);
                        e.target.value = "";
                      }}
                    />
                  )}
                </label>
                {!disabled && shown && !p.photo_uploading && (
                  <button
                    type="button"
                    aria-label={`Hapus foto petugas ${i + 1}`}
                    onClick={() =>
                      set(i, { photo_url: null, photo_preview: undefined })
                    }
                    className="absolute -right-1.5 -top-1.5 grid h-4 w-4 place-items-center rounded-full bg-[var(--surface)] text-muted-foreground shadow-sm ring-1 ring-border hover:text-destructive"
                  >
                    <X className="h-2.5 w-2.5" />
                  </button>
                )}
              </div>
              <span className="text-[0.625rem] leading-none text-muted-foreground">
                Upload Foto
              </span>
            </div>
            <Input
              className="min-w-[10rem] flex-1"
              placeholder="Nama petugas"
              aria-label={`Nama petugas ${i + 1}`}
              value={p.full_name}
              disabled={disabled}
              onChange={(e) => set(i, { full_name: nameInput(e.target.value) })}
            />
            <Select
              className="w-32 shrink-0"
              aria-label={`Jenis petugas ${i + 1}`}
              value={p.kind}
              disabled={disabled}
              onChange={(e) =>
                set(i, { kind: e.target.value as EventPersonnelKind })
              }
            >
              {KIND_OPTIONS.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </Select>
            <Input
              className="w-44 shrink-0"
              // The placeholder is the fallback the card would print, so an
              // empty field reads as the title it inherits rather than blank.
              placeholder={p.kind === "wasit" ? "Wasit" : "Staf"}
              aria-label={`Jabatan petugas ${i + 1}`}
              maxLength={60}
              value={p.role_label}
              disabled={disabled}
              onChange={(e) => set(i, { role_label: e.target.value })}
            />
            {!disabled && (
              <Button
                type="button"
                size="icon"
                variant="ghost"
                className="shrink-0 text-muted-foreground"
                aria-label={`Hapus petugas ${i + 1}`}
                onClick={() => onChange(personnel.filter((_, j) => j !== i))}
              >
                <X className="h-4 w-4" />
              </Button>
            )}
          </div>
        );
      })}

      {!disabled && (
        <div className="flex flex-wrap gap-2">
          <Button
            type="button"
            size="sm"
            variant="outline"
            onClick={() => onChange([...personnel, emptyPersonnel("wasit")])}
          >
            <Plus className="h-4 w-4" />
            Wasit
          </Button>
          <Button
            type="button"
            size="sm"
            variant="outline"
            onClick={() => onChange([...personnel, emptyPersonnel("staf")])}
          >
            <Plus className="h-4 w-4" />
            Staf
          </Button>
        </div>
      )}
    </div>
  );
}
