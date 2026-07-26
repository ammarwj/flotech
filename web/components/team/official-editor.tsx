"use client";

import { ImagePlus, Loader2, Plus, User, X } from "lucide-react";
import { toast } from "sonner";

import { useCatalog } from "@/lib/hooks/use-catalog";
import { compressToWebp } from "@/lib/image";
import { nameInput } from "@/lib/name";
import { uploadImage } from "@/lib/api/events";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";

export type OfficialRow = {
  id?: string;
  full_name: string;
  /** A role_key from the sport's master list, "" when unset. */
  role: string;
  photo_url?: string | null;
  /** Render-only: local blob for instant preview. */
  photo_preview?: string;
  /** Render-only: upload in flight. */
  photo_uploading?: boolean;
};

export const emptyOfficial = (): OfficialRow => ({ full_name: "", role: "" });

/** The photo to render for a row: local blob first, else a stored http(s) URL. */
function photoShown(o: OfficialRow): string | null {
  return o.photo_preview ?? (o.photo_url && /^https?:\/\//.test(o.photo_url) ? o.photo_url : null);
}

/**
 * The bench, shared by the same three places the roster is typed in: public
 * registration, the participant's own team page, and the organizer entering a
 * team by hand.
 *
 * Unlike RosterEditor there is no `size` and no racket-sport branch — a bench is
 * never the entry itself, so it is always a list to grow, and a badminton
 * tunggal player has a coach just as a football squad does.
 */
export function OfficialEditor({
  officials,
  onChange,
  sport,
  disabled,
}: {
  officials: OfficialRow[];
  onChange: (officials: OfficialRow[]) => void;
  /** Sport slug — decides which roles may be picked. */
  sport?: string | null;
  disabled?: boolean;
}) {
  const { officialRolesFor } = useCatalog();

  // The admin defines these per sport (sport_official_roles). A sport with none
  // has nothing to offer, and the API rejects any role on its bench — so the
  // column disappears rather than showing an empty dropdown.
  const roles = officialRolesFor(sport);

  const set = (i: number, patch: Partial<OfficialRow>) =>
    onChange(officials.map((o, j) => (j === i ? { ...o, ...patch } : o)));

  const uploadPhoto = async (i: number, file?: File | null) => {
    if (!file) return;
    if (!file.type.startsWith("image/")) {
      toast.error("Foto ofisial harus berupa gambar.");
      return;
    }
    if (file.size > 2 * 1024 * 1024) {
      toast.error("Ukuran foto maksimal 2 MB.");
      return;
    }
    try {
      const webp = await compressToWebp(file, { maxDim: 512, quality: 0.85 });
      set(i, { photo_preview: URL.createObjectURL(webp), photo_uploading: true });
      const url = await uploadImage(webp, "officials");
      set(i, { photo_url: url, photo_uploading: false });
    } catch {
      toast.error("Gagal mengunggah foto ofisial.");
      set(i, { photo_preview: undefined, photo_uploading: false });
    }
  };

  return (
    <div className="grid gap-2">
      {officials.map((o, i) => {
        const shown = photoShown(o);
        return (
          <div key={o.id ?? `new-${i}`} className="flex flex-wrap items-center gap-2">
            <div className="relative h-9 w-9 shrink-0">
              <label
                className={`grid h-9 w-9 place-items-center overflow-hidden rounded-md border border-border bg-[var(--bg-soft)] text-muted-foreground ${
                  disabled ? "" : "cursor-pointer hover:border-[var(--brand-500)]"
                }`}
                aria-label={`Foto ofisial ${i + 1}`}
              >
                {o.photo_uploading ? (
                  <Loader2 className="h-4 w-4 animate-spin" />
                ) : shown ? (
                  // eslint-disable-next-line @next/next/no-img-element
                  <img src={shown} alt={o.full_name || `Ofisial ${i + 1}`} className="h-full w-full object-cover" />
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
                    disabled={o.photo_uploading}
                    onChange={(e) => {
                      uploadPhoto(i, e.target.files?.[0]);
                      e.target.value = "";
                    }}
                  />
                )}
              </label>
              {!disabled && shown && !o.photo_uploading && (
                <button
                  type="button"
                  aria-label={`Hapus foto ofisial ${i + 1}`}
                  onClick={() => set(i, { photo_url: null, photo_preview: undefined })}
                  className="absolute -right-1.5 -top-1.5 grid h-4 w-4 place-items-center rounded-full bg-[var(--surface)] text-muted-foreground shadow-sm ring-1 ring-border hover:text-destructive"
                >
                  <X className="h-2.5 w-2.5" />
                </button>
              )}
            </div>
            <Input
              className="min-w-[10rem] flex-1"
              placeholder="Nama ofisial"
              aria-label={`Nama ofisial ${i + 1}`}
              value={o.full_name}
              disabled={disabled}
              onChange={(e) => set(i, { full_name: nameInput(e.target.value) })}
            />
            {roles.length > 0 && (
              <Select
                className="w-40 shrink-0"
                aria-label={`Peran ofisial ${i + 1}`}
                value={o.role}
                disabled={disabled}
                onChange={(e) => set(i, { role: e.target.value })}
              >
                <option value="">Peran</option>
                {roles.map((role) => (
                  <option key={role.key} value={role.key}>
                    {role.label}
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
                aria-label={`Hapus ofisial ${i + 1}`}
                onClick={() => onChange(officials.filter((_, j) => j !== i))}
              >
                <X className="h-4 w-4" />
              </Button>
            )}
          </div>
        );
      })}

      {!disabled && (
        <Button
          type="button"
          size="sm"
          variant="outline"
          className="justify-self-start"
          onClick={() => onChange([...officials, emptyOfficial()])}
        >
          <Plus className="h-4 w-4" />
          Ofisial
        </Button>
      )}
    </div>
  );
}
