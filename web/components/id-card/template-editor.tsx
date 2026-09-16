"use client";

import { useCallback, useRef, useState } from "react";
import { Trash2, User } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { clampPercent, useCanvasDrag, useCanvasWidth } from "@/lib/canvas-drag";
import { cn } from "@/lib/utils";
import type { IdCardField, IdCardFieldDef } from "@/types/api";

/** Sample text per field, so the canvas shows shapes instead of {{placeholders}}. */
const SAMPLE: Record<string, string> = {
  name: "Ahmad Fauzi Rahmatullah",
  role_label: "Wasit Utama",
  team_name: "Garuda FC",
  event_name: "Jakarta Cup 2026",
};

/** What a newly added field starts as. Photo and text begin life differently. */
const DEFAULTS = {
  photo: { x: 8, y: 12, w: 28, h: 58, fit: "cover", radius: 6 },
  text: { x: 42, y: 30, size: 4, color: "#111827", align: "left", wrap: 55 },
} as const;

/** Which part of a field the pointer grabbed. */
type Drag = {
  index: number;
  mode: "move" | "resize";
  /** Percent from the box's corner to the grab point, so it doesn't jump. */
  dx: number;
  dy: number;
};

/**
 * Places fields on the organizer's card artwork by dragging.
 *
 * A fork of the certificate editor rather than a shared component, because the
 * geometry differs rather than the styling: the page is (width_mm, height_mm)
 * from the row instead of A4-in-points, `size` is millimetres instead of points,
 * and a photo is a resizable **box** anchored at its top-left while text hangs
 * from the point it aligns to. The pointer plumbing both do share lives in
 * lib/canvas-drag.ts.
 *
 * The preview mirrors the renderer exactly — millimetres times the same
 * px-per-mm scale — so there is no fudge factor between what is arranged here
 * and what comes out of the printer.
 */
export function TemplateEditor({
  backgroundUrl,
  widthMm,
  heightMm,
  fields,
  fieldDefs,
  onChange,
}: {
  backgroundUrl: string;
  widthMm: number;
  heightMm: number;
  fields: IdCardField[];
  fieldDefs: IdCardFieldDef[];
  onChange: (fields: IdCardField[]) => void;
}) {
  const canvasRef = useRef<HTMLDivElement>(null);
  const canvasWidth = useCanvasWidth(canvasRef);
  const [selected, setSelected] = useState<number | null>(null);

  // Millimetres → screen pixels. Everything on the canvas is sized through this,
  // the same way the renderer sizes everything through px(mm).
  const scale = widthMm > 0 ? canvasWidth / widthMm : 0;
  const canvasHeight = canvasWidth * (widthMm > 0 ? heightMm / widthMm : 0);

  const onDragMove = useCallback(
    (drag: Drag, point: { x: number; y: number }) => {
      onChange(
        fields.map((f, i) => {
          if (i !== drag.index) return f;

          if (drag.mode === "resize") {
            // The far corner follows the pointer; the anchored corner stays put.
            // Floored at 1% because a zero-width box is invisible and therefore
            // unrecoverable without deleting the field.
            return {
              ...f,
              w: clampPercent(Math.max(1, point.x - f.x)),
              h: clampPercent(Math.max(1, point.y - f.y)),
            };
          }

          return { ...f, x: clampPercent(point.x - drag.dx), y: clampPercent(point.y - drag.dy) };
        })
      );
    },
    [fields, onChange]
  );

  const { start } = useCanvasDrag<Drag>(canvasRef, onDragMove);

  /** Where inside the field the pointer landed, as canvas percentages. */
  const grabOffset = (e: React.PointerEvent, field: IdCardField) => {
    const rect = canvasRef.current?.getBoundingClientRect();
    if (!rect || rect.width === 0 || rect.height === 0) return { dx: 0, dy: 0 };

    return {
      dx: ((e.clientX - rect.left) / rect.width) * 100 - field.x,
      dy: ((e.clientY - rect.top) / rect.height) * 100 - field.y,
    };
  };

  const update = (index: number, patch: Partial<IdCardField>) =>
    onChange(fields.map((f, i) => (i === index ? { ...f, ...patch } : f)));

  const remove = (index: number) => {
    onChange(fields.filter((_, i) => i !== index));
    setSelected(null);
  };

  const add = (key: string) => {
    if (!key) return;
    // The two shapes are not interchangeable and the API refuses a mixture, so
    // a new field is born with exactly the keys its shape is allowed to carry.
    onChange([...fields, { key, ...(key === "photo" ? DEFAULTS.photo : DEFAULTS.text) }]);
    setSelected(fields.length);
  };

  const unused = fieldDefs.filter((def) => !fields.some((f) => f.key === def.key));
  const active = selected !== null ? fields[selected] : undefined;
  const labelOf = (key: string) => fieldDefs.find((d) => d.key === key)?.label ?? key;

  return (
    <div className="grid gap-6 lg:grid-cols-[1fr_280px]">
      <div>
        <div
          ref={canvasRef}
          className="relative w-full select-none overflow-hidden rounded-xl border border-border bg-[var(--bg-soft)]"
          style={{ aspectRatio: `${widthMm} / ${heightMm}` }}
        >
          {backgroundUrl && (
            // eslint-disable-next-line @next/next/no-img-element
            <img
              src={backgroundUrl}
              alt=""
              className="absolute inset-0 h-full w-full"
              style={{ objectFit: "fill" }}
              draggable={false}
            />
          )}

          {fields.map((field, index) => {
            const isSelected = selected === index;

            if (field.key === "photo") {
              const boxW = ((field.w ?? 0) / 100) * canvasWidth;
              const boxH = ((field.h ?? 0) / 100) * canvasHeight;

              return (
                <div
                  key={index}
                  onPointerDown={(e) => {
                    e.preventDefault();
                    start({ index, mode: "move", ...grabOffset(e, field) });
                    setSelected(index);
                  }}
                  className={cn(
                    "absolute cursor-move outline-offset-2",
                    isSelected && "outline outline-2 outline-[var(--brand-600)]"
                  )}
                  style={{
                    left: `${field.x}%`,
                    top: `${field.y}%`,
                    width: `${field.w ?? 0}%`,
                    height: `${field.h ?? 0}%`,
                    // Percent of the shorter side, matching the renderer. A CSS
                    // percentage radius would be per-axis and turn a square
                    // corner into an ellipse on any non-square box.
                    borderRadius: (((field.radius ?? 0) / 100) * Math.min(boxW, boxH)) || 0,
                  }}
                >
                  <div
                    className="grid h-full w-full place-items-center border border-dashed border-[var(--brand-600)] bg-white/70 text-[var(--brand-600)]"
                    style={{ borderRadius: "inherit" }}
                  >
                    <User className="h-1/3 w-1/3" />
                  </div>

                  {isSelected && (
                    /* Resize handle. Its own pointerdown stops the parent's, or
                       every resize would start a move as well. */
                    <span
                      onPointerDown={(e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        start({ index, mode: "resize", dx: 0, dy: 0 });
                      }}
                      className="absolute -bottom-1.5 -right-1.5 h-3 w-3 cursor-nwse-resize rounded-full border border-white bg-[var(--brand-600)]"
                    />
                  )}
                </div>
              );
            }

            const align = field.align ?? "left";

            // x is the point the text aligns *to*, the same as the renderer and
            // the same as the certificate editor, so both read alike.
            const transform =
              align === "left"
                ? "none"
                : align === "right"
                  ? "translateX(-100%)"
                  : "translateX(-50%)";

            return (
              <div
                key={index}
                onPointerDown={(e) => {
                  e.preventDefault();
                  start({ index, mode: "move", ...grabOffset(e, field) });
                  setSelected(index);
                }}
                className={cn(
                  "absolute cursor-move outline-offset-2",
                  isSelected && "outline outline-2 outline-[var(--brand-600)]"
                )}
                style={{
                  left: `${field.x}%`,
                  top: `${field.y}%`,
                  transform,
                  // The wrap width the renderer will use, so a long name breaks
                  // in the same place on screen as on paper.
                  maxWidth: `${((field.wrap ?? 100) / 100) * canvasWidth}px`,
                }}
              >
                <span
                  className="block"
                  style={{
                    fontSize: (field.size ?? 0) * scale,
                    color: field.color ?? "#111827",
                    fontWeight: field.bold ? 700 : 400,
                    textTransform: field.uppercase ? "uppercase" : "none",
                    textAlign: align,
                    lineHeight: 1.2,
                  }}
                >
                  {SAMPLE[field.key] ?? labelOf(field.key)}
                </span>
              </div>
            );
          })}
        </div>

        <p className="mt-2 text-xs text-muted-foreground">
          Geser field ke posisinya; kotak foto bisa ditarik sudutnya untuk diubah ukuran. Teks
          contoh hanya pratinjau — saat dicetak, isinya diambil dari data event.
        </p>
      </div>

      <div className="flex flex-col gap-4">
        <div>
          <Label htmlFor="add-field">Tambah field</Label>
          <Select
            id="add-field"
            value=""
            onChange={(e) => add(e.target.value)}
            disabled={unused.length === 0}
            className="mt-1.5"
          >
            <option value="">
              {unused.length ? "— pilih field —" : "Semua field sudah dipakai"}
            </option>
            {unused.map((def) => (
              <option key={def.key} value={def.key}>
                {def.label}
              </option>
            ))}
          </Select>
        </div>

        {fields.length > 0 && (
          <div className="rounded-lg border border-border">
            {fields.map((field, index) => (
              <button
                key={index}
                type="button"
                onClick={() => setSelected(index)}
                className={cn(
                  "flex w-full items-center justify-between px-3 py-2 text-left text-sm",
                  selected === index
                    ? "bg-[var(--tint)] text-[var(--brand-600)]"
                    : "hover:bg-[var(--bg-soft)]"
                )}
              >
                <span className="truncate">{labelOf(field.key)}</span>
                <span className="text-xs text-muted-foreground">
                  {field.x}% · {field.y}%
                </span>
              </button>
            ))}
          </div>
        )}

        {active && selected !== null && (
          <div className="flex flex-col gap-3 rounded-lg border border-border p-3">
            <div className="flex items-center justify-between">
              <span className="text-sm font-semibold">{labelOf(active.key)}</span>
              <Button size="sm" variant="ghost" onClick={() => remove(selected)}>
                <Trash2 className="h-4 w-4" />
              </Button>
            </div>

            <div className="grid grid-cols-2 gap-2">
              <div>
                <Label htmlFor="f-x">X (%)</Label>
                <Input
                  id="f-x"
                  type="number"
                  value={active.x}
                  onChange={(e) => update(selected, { x: Number(e.target.value) })}
                  className="mt-1"
                />
              </div>
              <div>
                <Label htmlFor="f-y">Y (%)</Label>
                <Input
                  id="f-y"
                  type="number"
                  value={active.y}
                  onChange={(e) => update(selected, { y: Number(e.target.value) })}
                  className="mt-1"
                />
              </div>
            </div>

            {active.key === "photo" ? (
              <>
                <div className="grid grid-cols-2 gap-2">
                  <div>
                    <Label htmlFor="f-w">Lebar (%)</Label>
                    <Input
                      id="f-w"
                      type="number"
                      value={active.w ?? 0}
                      onChange={(e) => update(selected, { w: Number(e.target.value) })}
                      className="mt-1"
                    />
                  </div>
                  <div>
                    <Label htmlFor="f-h">Tinggi (%)</Label>
                    <Input
                      id="f-h"
                      type="number"
                      value={active.h ?? 0}
                      onChange={(e) => update(selected, { h: Number(e.target.value) })}
                      className="mt-1"
                    />
                  </div>
                </div>

                <div>
                  <Label htmlFor="f-fit">Penyesuaian</Label>
                  <Select
                    id="f-fit"
                    value={active.fit ?? "cover"}
                    onChange={(e) => update(selected, { fit: e.target.value as IdCardField["fit"] })}
                    className="mt-1"
                  >
                    <option value="cover">Penuhi kotak (dipotong)</option>
                    <option value="contain">Muat seluruhnya</option>
                  </Select>
                  <p className="mt-1 text-xs text-muted-foreground">
                    Pasfoto 3:4 di kotak yang lebih lebar akan terpotong di ubun-ubun — pilih
                    &ldquo;muat seluruhnya&rdquo; kalau itu masalah.
                  </p>
                </div>

                <div>
                  <Label htmlFor="f-radius">Sudut membulat (%)</Label>
                  <Input
                    id="f-radius"
                    type="number"
                    min={0}
                    max={50}
                    value={active.radius ?? 0}
                    onChange={(e) => update(selected, { radius: Number(e.target.value) })}
                    className="mt-1"
                  />
                  <p className="mt-1 text-xs text-muted-foreground">0 = kotak, 50 = lingkaran.</p>
                </div>
              </>
            ) : (
              <>
                <div>
                  <Label htmlFor="f-size">Tinggi huruf (mm)</Label>
                  <Input
                    id="f-size"
                    type="number"
                    step="0.5"
                    value={active.size ?? 0}
                    onChange={(e) => update(selected, { size: Number(e.target.value) })}
                    className="mt-1"
                  />
                  <p className="mt-1 text-xs text-muted-foreground">
                    Milimeter, bukan pt — ukurannya ikut benar saat ukuran kartu diganti.
                  </p>
                </div>

                <div>
                  <Label htmlFor="f-wrap">Lebar maksimum (%)</Label>
                  <Input
                    id="f-wrap"
                    type="number"
                    min={10}
                    max={100}
                    value={active.wrap ?? 100}
                    onChange={(e) => update(selected, { wrap: Number(e.target.value) })}
                    className="mt-1"
                  />
                </div>

                <div>
                  <Label htmlFor="f-align">Perataan</Label>
                  <Select
                    id="f-align"
                    value={active.align ?? "left"}
                    onChange={(e) =>
                      update(selected, { align: e.target.value as IdCardField["align"] })
                    }
                    className="mt-1"
                  >
                    <option value="left">Kiri</option>
                    <option value="center">Tengah</option>
                    <option value="right">Kanan</option>
                  </Select>
                </div>

                <div>
                  <Label htmlFor="f-color">Warna</Label>
                  <Input
                    id="f-color"
                    type="color"
                    value={active.color ?? "#111827"}
                    onChange={(e) => update(selected, { color: e.target.value })}
                    className="mt-1 h-10 p-1"
                  />
                </div>

                <label className="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    checked={active.bold ?? false}
                    onChange={(e) => update(selected, { bold: e.target.checked })}
                  />
                  Tebal
                </label>

                <label className="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    checked={active.uppercase ?? false}
                    onChange={(e) => update(selected, { uppercase: e.target.checked })}
                  />
                  HURUF KAPITAL
                </label>
              </>
            )}
          </div>
        )}
      </div>
    </div>
  );
}
