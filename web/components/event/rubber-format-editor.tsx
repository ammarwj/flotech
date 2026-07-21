"use client";

import { Plus, Trash2 } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import type { RubberFormatRow } from "@/types/api";

/** What a badminton beregu tie usually looks like — one click to get started. */
const PRESET: RubberFormatRow[] = [
  { label: "Ganda Putra", type: "double" },
  { label: "Tunggal Putra", type: "single" },
  { label: "Ganda Campuran", type: "double" },
];

/**
 * The partai a squad tie is played over ("1. Ganda Putra, 2. Tunggal Putra, …").
 *
 * This is a template: every fixture generated for the category is born with
 * these, and each one can still be adjusted on the match itself.
 */
export function RubberFormatEditor({
  value,
  onChange,
}: {
  value: RubberFormatRow[] | null | undefined;
  onChange: (rows: RubberFormatRow[] | null) => void;
}) {
  const rows = value ?? [];

  const patch = (i: number, next: Partial<RubberFormatRow>) =>
    onChange(rows.map((r, j) => (j === i ? { ...r, ...next } : r)));

  const remove = (i: number) => {
    const next = rows.filter((_, j) => j !== i);
    // An empty template means "not a partai format at all", which is what null
    // says to the backend — an empty array would read the same but store noise.
    onChange(next.length ? next : null);
  };

  return (
    <div className="grid gap-3 rounded-lg border border-dashed border-border p-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <Label className="font-semibold">Partai per pertandingan</Label>
          <p className="text-xs text-muted-foreground">
            Hasil tim dihitung dari partai yang dimenangkan, mis. Spanyol 3-0 Argentina.
          </p>
        </div>
        {rows.length === 0 && (
          <Button type="button" size="sm" variant="outline" onClick={() => onChange(PRESET)}>
            Pakai format standar
          </Button>
        )}
      </div>

      {rows.map((row, i) => (
        <div key={i} className="flex items-center gap-2">
          <span className="w-5 shrink-0 text-sm tabular-nums text-muted-foreground">{i + 1}.</span>
          <Input
            value={row.label}
            onChange={(e) => patch(i, { label: e.target.value })}
            placeholder="Ganda Putra"
            className="flex-1"
          />
          {/* Select renders its own relative wrapper, so the width goes here. */}
          <div className="w-32 shrink-0">
            <Select
              value={row.type}
              onChange={(e) => patch(i, { type: e.target.value as RubberFormatRow["type"] })}
            >
              <option value="single">Tunggal</option>
              <option value="double">Ganda</option>
            </Select>
          </div>
          <Button
            type="button"
            size="sm"
            variant="ghost"
            className="h-9 shrink-0 px-2 text-muted-foreground hover:text-destructive"
            onClick={() => remove(i)}
            aria-label={`Hapus partai ${i + 1}`}
          >
            <Trash2 className="h-3.5 w-3.5" />
          </Button>
        </div>
      ))}

      <Button
        type="button"
        size="sm"
        variant="outline"
        className="gap-1.5 justify-self-start"
        onClick={() => onChange([...rows, { label: "", type: "single" }])}
      >
        <Plus className="h-3.5 w-3.5" />
        Tambah partai
      </Button>

      {rows.length === 0 && (
        <p className="text-xs text-muted-foreground">
          Tanpa partai, pertandingan tim diisi satu skor seperti biasa.
        </p>
      )}
    </div>
  );
}
