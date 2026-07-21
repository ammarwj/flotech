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

export type PlayerRow = {
  id?: string;
  full_name: string;
  jersey_number: string;
  position: string;
  /** Optional profile photo — the stored URL. */
  photo_url?: string | null;
  /** Render-only: local blob for instant preview (dev storage URLs aren't always renderable). */
  photo_preview?: string;
  /** Render-only: upload in flight. */
  photo_uploading?: boolean;
};

export const emptyPlayer = (): PlayerRow => ({ full_name: "", jersey_number: "", position: "" });

/**
 * Pin a roster to the exact length a singles/doubles category requires, padding
 * with blanks and dropping the surplus. Called wherever such an entry is edited,
 * so the form can only ever produce a roster the backend will accept.
 */
export function fixedRoster(players: PlayerRow[], size: number): PlayerRow[] {
  return Array.from({ length: size }, (_, i) => players[i] ?? emptyPlayer());
}

/** The photo to render for a row: local blob first, else a stored http(s) URL. */
function photoShown(p: PlayerRow): string | null {
  return p.photo_preview ?? (p.photo_url && /^https?:\/\//.test(p.photo_url) ? p.photo_url : null);
}

/**
 * The roster table, shared by the three places a squad gets typed in: public
 * registration, the participant's own team page, and the organizer entering a
 * team by hand. Same columns everywhere — photo (optional), name, number,
 * position — so a roster doesn't lose a field depending on who filled it.
 */
export function RosterEditor({
  players,
  onChange,
  sport,
  disabled,
  size,
}: {
  players: PlayerRow[];
  onChange: (players: PlayerRow[]) => void;
  /** Sport slug — decides which positions may be picked. */
  sport?: string | null;
  disabled?: boolean;
  /**
   * Exactly how many players this entry has (1 tunggal, 2 ganda), or null for a
   * squad whose size is the organizer's business. A fixed roster is not a list
   * to grow: the rows are the entry, so there is nothing to add or remove.
   */
  size?: number | null;
}) {
  const { positionsFor } = useCatalog();
  const fixed = typeof size === "number";

  // The admin defines these per sport (sport_positions). A sport with none has
  // nothing to offer, and the API rejects any position on its rosters — so the
  // column disappears rather than showing an empty dropdown.
  const positions = positionsFor(sport);

  const set = (i: number, patch: Partial<PlayerRow>) =>
    onChange(players.map((p, j) => (j === i ? { ...p, ...patch } : p)));

  const uploadPhoto = async (i: number, file?: File | null) => {
    if (!file) return;
    if (!file.type.startsWith("image/")) {
      toast.error("Foto pemain harus berupa gambar.");
      return;
    }
    if (file.size > 2 * 1024 * 1024) {
      toast.error("Ukuran foto maksimal 2 MB.");
      return;
    }
    // Compress + instant local preview, then upload and keep the returned URL.
    try {
      const webp = await compressToWebp(file, { maxDim: 512, quality: 0.85 });
      set(i, { photo_preview: URL.createObjectURL(webp), photo_uploading: true });
      const url = await uploadImage(webp, "players");
      set(i, { photo_url: url, photo_uploading: false });
    } catch {
      toast.error("Gagal mengunggah foto pemain.");
      set(i, { photo_preview: undefined, photo_uploading: false });
    }
  };

  return (
    <div className="grid gap-2">
      {players.map((p, i) => {
        const shown = photoShown(p);
        return (
          // Wraps on a narrow form: number, position and the remove button drop
          // to a second line rather than squeezing the name field to nothing.
          <div key={p.id ?? `new-${i}`} className="flex flex-wrap items-center gap-2">
            {/* Optional profile photo. A label wrapping a hidden input keeps it a
                single self-contained control per row. */}
            <div className="relative h-9 w-9 shrink-0">
              <label
                className={`grid h-9 w-9 place-items-center overflow-hidden rounded-md border border-border bg-[var(--bg-soft)] text-muted-foreground ${
                  disabled ? "" : "cursor-pointer hover:border-[var(--brand-500)]"
                }`}
                aria-label={`Foto pemain ${i + 1}`}
              >
                {p.photo_uploading ? (
                  <Loader2 className="h-4 w-4 animate-spin" />
                ) : shown ? (
                  // eslint-disable-next-line @next/next/no-img-element
                  <img src={shown} alt={p.full_name || `Pemain ${i + 1}`} className="h-full w-full object-cover" />
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
                  aria-label={`Hapus foto pemain ${i + 1}`}
                  onClick={() => set(i, { photo_url: null, photo_preview: undefined })}
                  className="absolute -right-1.5 -top-1.5 grid h-4 w-4 place-items-center rounded-full bg-[var(--surface)] text-muted-foreground shadow-sm ring-1 ring-border hover:text-destructive"
                >
                  <X className="h-2.5 w-2.5" />
                </button>
              )}
            </div>
            <Input
              // flex-1 rather than the default w-full: in a wrapping row a
              // 100%-wide item claims a whole line to itself. min-w keeps the
              // name readable — it forces the row to wrap instead of shrinking
              // the one field that matters down to a few characters.
              className="min-w-[10rem] flex-1"
              placeholder={fixed && size > 1 ? `Pemain ${i + 1}` : "Nama pemain"}
              aria-label={`Nama pemain ${i + 1}`}
              value={p.full_name}
              disabled={disabled}
              onChange={(e) => set(i, { full_name: nameInput(e.target.value) })}
            />
            <Input
              className="w-20 shrink-0"
              placeholder="No."
              aria-label={`Nomor punggung pemain ${i + 1}`}
              inputMode="numeric"
              value={p.jersey_number}
              disabled={disabled}
              // A jersey number is digits only — drop letters/symbols as they type or paste.
              onChange={(e) => set(i, { jersey_number: e.target.value.replace(/\D/g, "") })}
            />
            {positions.length > 0 && !fixed && (
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
            {!disabled && !fixed && (
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
        );
      })}

      {!disabled && !fixed && (
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
