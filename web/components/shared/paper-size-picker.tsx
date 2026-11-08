"use client";

import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { cn } from "@/lib/utils";

export interface SizePreset {
  label: string;
  hint: string;
  w: number;
  h: number;
}

/** Floats compared with a tolerance: 85.6 does not survive a round trip exactly. */
const matches = (a: number, b: number) => Math.abs(a - b) < 0.05;

/**
 * Two millimetre inputs with one-click presets above them.
 *
 * Shared by the ID card and e-BIB template forms, and the presets are a
 * **prop** rather than a constant in here: that is the whole of what differs
 * between them (CR80/A6/A7 against A5/A6), and the API never sees an enum
 * either way — a preset just writes two numbers. That is what lets an organizer
 * type a size nobody anticipated without a migration, while the common cases
 * stay one click away.
 */
export function PaperSizePicker({
  label,
  presets,
  widthMm,
  heightMm,
  onChange,
  errors,
}: {
  label: string;
  presets: readonly SizePreset[];
  widthMm: number;
  heightMm: number;
  onChange: (size: { width_mm: number; height_mm: number }) => void;
  errors?: Record<string, string>;
}) {
  const active = presets.find((p) => matches(p.w, widthMm) && matches(p.h, heightMm));

  return (
    <div className="grid gap-3">
      <Label>{label}</Label>

      <div className="flex flex-wrap gap-2">
        {presets.map((preset) => (
          <button
            key={preset.label}
            type="button"
            onClick={() => onChange({ width_mm: preset.w, height_mm: preset.h })}
            className={cn(
              "rounded-lg border px-3 py-2 text-left text-sm transition-colors",
              active?.label === preset.label
                ? "border-[var(--brand-600)] bg-[var(--tint)] text-[var(--brand-600)]"
                : "border-border hover:bg-[var(--bg-soft)]"
            )}
          >
            <span className="block font-semibold">{preset.label}</span>
            <span className="block text-xs text-muted-foreground">
              {preset.w} × {preset.h} mm
            </span>
          </button>
        ))}

        {/* Not a button: "Kustom" is what the two inputs below already are, so a
            further button would have nothing to do but un-highlight the others. */}
        <div
          className={cn(
            "rounded-lg border px-3 py-2 text-sm",
            active
              ? "border-border text-muted-foreground"
              : "border-[var(--brand-600)] bg-[var(--tint)] text-[var(--brand-600)]"
          )}
        >
          <span className="block font-semibold">Kustom</span>
          <span className="block text-xs">Isi sendiri di bawah</span>
        </div>
      </div>

      <div className="grid grid-cols-2 gap-3">
        <div>
          <Label htmlFor="width-mm">Lebar (mm)</Label>
          <Input
            id="width-mm"
            type="number"
            step="0.1"
            min={20}
            max={400}
            value={widthMm}
            onChange={(e) => onChange({ width_mm: Number(e.target.value), height_mm: heightMm })}
            className="mt-1"
          />
          {errors?.width_mm && <p className="mt-1 text-sm text-destructive">{errors.width_mm}</p>}
        </div>
        <div>
          <Label htmlFor="height-mm">Tinggi (mm)</Label>
          <Input
            id="height-mm"
            type="number"
            step="0.1"
            min={20}
            max={400}
            value={heightMm}
            onChange={(e) => onChange({ width_mm: widthMm, height_mm: Number(e.target.value) })}
            className="mt-1"
          />
          {errors?.height_mm && <p className="mt-1 text-sm text-destructive">{errors.height_mm}</p>}
        </div>
      </div>
    </div>
  );
}
