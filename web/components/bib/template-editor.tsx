"use client";

import { useCallback, useRef, useState } from "react";
import { QrCode, Trash2 } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { clampPercent, useCanvasDrag, useCanvasWidth } from "@/lib/canvas-drag";
import { cn } from "@/lib/utils";
import type { BibField, BibFieldDef } from "@/types/api";

/** Sample text per field, so the canvas shows shapes instead of {{placeholders}}. */
const SAMPLE: Record<string, string> = {
  bib_number: "5K-001",
  name: "Ahmad Fauzi Rahmatullah",
  category_name: "5K Umum",
  event_name: "Jakarta Fun Run 2026",
};

/**
 * What a newly added field starts as. Two shapes begin life differently, and
 * the API refuses any mixture — see BibTemplateRules::check().
 *
 * `bib_number` starts far larger than any other text, because it is the one
 * thing on the sheet meant to be read from across a finish line. 60mm on A5 is
 * the ordinary size for a race number; the rest of the text is caption-sized
 * beside it.
 *
 * The QR default is 18% of sheet width, which on A5 landscape is ~38mm — far
 * past the point a phone camera struggles, which is why the warning below
 * almost never fires here. It is kept anyway: an organizer who switches to A6
 * can walk into the same trap the card editor exists to warn about.
 */
const DEFAULTS = {
  // No `fit`, no `radius`: `contain` means nothing on something already square,
  // and rounding the corners eats the finder patterns a scanner locates the
  // code by. Both are refused by the API rather than ignored — and here there
  // is no photo field for them to belong to at all.
  qr: { x: 76, y: 62, w: 18, h: 18 },
  bibNumber: { x: 50, y: 22, size: 60, color: "#111827", align: "center", bold: true, wrap: 90 },
  text: { x: 50, y: 72, size: 8, color: "#111827", align: "center", wrap: 80 },
} as const;

/**
 * Below this, a module falls under ~0.4mm and the printed code stops scanning
 * reliably. A warning rather than a floor: paper and printer quality decide the
 * real limit, and an organizer who tested theirs should not be blocked by our
 * guess. The renderer draws nothing at all once a module would be under one
 * pixel, which is far smaller still.
 */
const QR_MIN_MM = 14;

/** Which part of a field the pointer grabbed. */
type Drag = {
  index: number;
  mode: "move" | "resize";
  /** Percent from the box's corner to the grab point, so it doesn't jump. */
  dx: number;
  dy: number;
};

/**
 * Places fields on the organizer's e-BIB artwork by dragging.
 *
 * A fork of the ID card editor rather than a shared component, the same call
 * the API makes about the two tables: the geometry is identical but the field
 * catalogues are not. There is no photo here, so the box branch serves `qr`
 * alone and the fit/radius controls have nothing to belong to — a shared editor
 * would have to be told which catalogue it was editing, and that parameter is
 * the branch this avoids. The pointer plumbing both do share lives in
 * lib/canvas-drag.ts.
 *
 * The preview mirrors the renderer exactly — millimetres times the same
 * px-per-mm scale — so there is no fudge factor between what is arranged here
 * and what comes out of the printer.
 */
export function BibTemplateEditor({
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
  fields: BibField[];
  fieldDefs: BibFieldDef[];
  onChange: (fields: BibField[]) => void;
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
  const grabOffset = (e: React.PointerEvent, field: BibField) => {
    const rect = canvasRef.current?.getBoundingClientRect();
    if (!rect || rect.width === 0 || rect.height === 0) return { dx: 0, dy: 0 };

    return {
      dx: ((e.clientX - rect.left) / rect.width) * 100 - field.x,
      dy: ((e.clientY - rect.top) / rect.height) * 100 - field.y,
    };
  };

  const update = (index: number, patch: Partial<BibField>) =>
    onChange(fields.map((f, i) => (i === index ? { ...f, ...patch } : f)));

  const remove = (index: number) => {
    onChange(fields.filter((_, i) => i !== index));
    setSelected(null);
  };

  const add = (key: string) => {
    if (!key) return;
    // The shapes are not interchangeable and the API refuses a mixture, so a
    // new field is born with exactly the keys its shape is allowed to carry.
    const shape =
      key === "qr" ? DEFAULTS.qr : key === "bib_number" ? DEFAULTS.bibNumber : DEFAULTS.text;

    onChange([...fields, { key, ...shape }]);
    setSelected(fields.length);
  };

  const unused = fieldDefs.filter((def) => !fields.some((f) => f.key === def.key));
  const active = selected !== null ? fields[selected] : undefined;
  const labelOf = (key: string) => fieldDefs.find((d) => d.key === key)?.label ?? key;

  // The printed side of the selected QR, squared off the shorter side exactly
  // as the renderer does. Zero when the selection is not a QR; the branch that
  // reads it is already behind that check.
  const qrSideMm = Math.min(
    ((active?.w ?? 0) / 100) * widthMm,
    ((active?.h ?? 0) / 100) * heightMm
  );

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

            // A box anchored at its top-left and resized from the far corner,
            // because a QR is a square drawn into an area rather than a string
            // hanging off an alignment point.
            if (field.key === "qr") {
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
                  }}
                >
                  <div className="grid h-full w-full place-items-center border border-dashed border-[var(--brand-600)] bg-white/70 text-[var(--brand-600)]">
                    {/* Squared off the shorter side, exactly as the renderer
                        does: w != h leaves slack rather than stretching the
                        code, so the preview must not show it filling a
                        rectangle it will never fill. */}
                    <QrCode
                      style={{
                        width: Math.min(boxW, boxH) * 0.8,
                        height: Math.min(boxW, boxH) * 0.8,
                      }}
                    />
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
            // the same as the other two editors, so all three read alike.
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
          Geser field ke posisinya; kotak QR bisa ditarik sudutnya untuk diubah ukuran. Teks
          contoh hanya pratinjau — saat dicetak, isinya diambil dari data pendaftaran.
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

            {active.key === "qr" ? (
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

                <p className="text-xs text-muted-foreground">
                  QR ini <strong>tiket race pack</strong> pelari — petugas memindainya untuk
                  check-in. Kotaknya dibuat bujur sangkar dari sisi terpendek, jadi lebar dan
                  tinggi yang berbeda hanya menyisakan ruang kosong.
                </p>

                {/* In millimetres, not percent: 18% of an A5 sheet and 18% of an
                    A6 one are very different codes, and the mm figure is the one
                    an organizer can hold a ruler against — the same reason
                    `size` is in mm. */}
                <p
                  className={cn(
                    "text-xs",
                    qrSideMm < QR_MIN_MM ? "text-[var(--danger)]" : "text-muted-foreground"
                  )}
                >
                  Ukuran cetak QR: {qrSideMm.toFixed(1)} mm.
                  {qrSideMm < QR_MIN_MM
                    ? ` Di bawah ~${QR_MIN_MM} mm, kamera ponsel sering gagal memindainya — uji cetak dulu sebelum memakai ukuran ini.`
                    : " Cukup untuk dipindai di meja race pack."}
                </p>
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
                    Milimeter, bukan pt — ukurannya ikut benar saat ukuran kertas diganti. Nomor
                    BIB biasanya 50–80 mm supaya terbaca dari kejauhan.
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
                      update(selected, { align: e.target.value as BibField["align"] })
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
