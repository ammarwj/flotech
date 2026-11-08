"use client";

import { PaperSizePicker, type SizePreset } from "@/components/shared/paper-size-picker";

/**
 * Card sizes, in millimetres.
 *
 * The presets are the only thing this file holds — the control itself is shared
 * with the e-BIB template form, which offers a different set over the same two
 * inputs. The API never sees an enum either way: a preset just writes two
 * numbers, which is what lets an organizer type a size nobody anticipated
 * without a migration.
 */
const PRESETS: readonly SizePreset[] = [
  { label: "CR80", hint: "Kartu lanyard", w: 85.6, h: 54 },
  { label: "A6", hint: "Kartu besar", w: 105, h: 148 },
  { label: "A7", hint: "Setengah A6", w: 74, h: 105 },
] as const;

export function CardSizePicker({
  widthMm,
  heightMm,
  onChange,
  errors,
}: {
  widthMm: number;
  heightMm: number;
  onChange: (size: { width_mm: number; height_mm: number }) => void;
  errors?: Record<string, string>;
}) {
  return (
    <PaperSizePicker
      label="Ukuran kartu"
      presets={PRESETS}
      widthMm={widthMm}
      heightMm={heightMm}
      onChange={onChange}
      errors={errors}
    />
  );
}
