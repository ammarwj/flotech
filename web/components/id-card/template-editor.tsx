"use client";

import { useCallback, useRef, useState } from "react";
import { QrCode, Trash2, User } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { clampPercent, useCanvasDrag, useCanvasWidth } from "@/lib/canvas-drag";
import { fitFontSize } from "@/lib/fit-text";
import { fieldLabel, groupFields, isCustomFieldKey } from "@/lib/template-fields";
import { cn } from "@/lib/utils";
import type { IdCardField, IdCardFieldDef } from "@/types/api";

/** Sample text per field, so the canvas shows shapes instead of {{placeholders}}. */
const SAMPLE: Record<string, string> = {
  name: "Ahmad Fauzi Rahmatullah",
  role_label: "Wasit Utama",
  team_name: "Garuda FC",
  event_name: "Jakarta Cup 2026",
  category_name: "Putra U-17",
  jersey_number: "10",
  position: "Penyerang",
  date_of_birth: "17 Agustus 2004",
  card_number: "IDC-2026-11-0042",
  organization_name: "Pemuda Sport Center",
  event_date: "12 Desember 2026",
};

/**
 * What a newly added field starts as. Three shapes begin life differently, and
 * the API refuses any mixture — see IdCardTemplateRules::check().
 *
 * The QR default is 20% of card width, which on CR80 is ~17mm: at 300 DPI that
 * is roughly 0.5mm per module for a verification URL, about the smallest a phone
 * camera reads reliably at arm's length. Smaller is allowed; the warning below
 * is what says so.
 */
const DEFAULTS = {
  photo: { x: 8, y: 12, w: 28, h: 58, fit: "cover", radius: 6 },
  // No `fit`, no `radius`: `contain` means nothing on something already square,
  // and rounding the corners eats the finder patterns a scanner locates the
  // code by. Both are refused by the API rather than ignored.
  qr: { x: 70, y: 56, w: 20, h: 20 },
  /*
   * `valign: "middle"` for new fields, while an omitted key still means `top`
   * on the server so older templates render unchanged. Middle is what a name
   * plate wants: anchored at the top, "ADIB" sits high in its box while a name
   * long enough to wrap grows downwards out of it.
   *
   * `w`/`h` are born set, and that is the point of giving text a box at all:
   * without a height the renderer cannot shrink a long name, so a template
   * designed around "ADIB" breaks on "AHMAD FAUZI RAHMATULLAH" and nobody finds
   * out until the batch is printed. `w` supersedes `wrap`, which stays in the
   * payload only for templates saved before this existed.
   */
  text: {
    x: 42,
    y: 30,
    w: 55,
    h: 14,
    size: 4,
    color: "#111827",
    align: "left",
    valign: "middle",
  },
} as const;

/**
 * Below this, a module falls under ~0.4mm and the printed code stops scanning
 * reliably. A warning rather than a floor: card stock and printer quality
 * decide the real limit, and an organizer who tested theirs should not be
 * blocked by our guess. The renderer draws nothing at all once a module would
 * be under one pixel, which is far smaller still.
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
            // Floored at 1% because a zero-width box is invisible and therefore
            // unrecoverable without deleting the field.
            const sized = (value: number) => clampPercent(Math.max(1, value));

            // Two anchoring conventions, so two resize rules — and the split is
            // the same one the rest of this editor already lives with. A box
            // (`photo`/`qr`) is pinned at its top-left, so the far corner simply
            // follows the pointer. A TEXT field hangs from its alignment point,
            // which may sit anywhere along the box, so what has to stay still is
            // that anchor: growing a centred name plate has to grow it both
            // ways, or the text slides sideways as the organizer drags.
            if (f.key === "photo" || f.key === "qr") {
              return { ...f, w: sized(point.x - f.x), h: sized(point.y - f.y) };
            }

            const align = f.align ?? "left";
            const valign = f.valign ?? "top";

            return {
              ...f,
              w: sized(
                align === "left"
                  ? point.x - f.x
                  : align === "right"
                    ? f.x - point.x
                    : 2 * Math.abs(point.x - f.x)
              ),
              h: sized(
                valign === "top"
                  ? point.y - f.y
                  : valign === "bottom"
                    ? f.y - point.y
                    : 2 * Math.abs(point.y - f.y)
              ),
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
    // The three shapes are not interchangeable and the API refuses a mixture,
    // so a new field is born with exactly the keys its shape is allowed to
    // carry.
    const shape =
      key === "photo" ? DEFAULTS.photo : key === "qr" ? DEFAULTS.qr : DEFAULTS.text;

    onChange([...fields, { key, ...shape }]);
    setSelected(fields.length);
  };

  const unused = groupFields(fieldDefs.filter((def) => !fields.some((f) => f.key === def.key)));
  const unusedCount = unused.reduce((n, g) => n + g.defs.length, 0);
  const active = selected !== null ? fields[selected] : undefined;
  // Through the helper, not a lookup with the key as fallback: a template is
  // org-scoped and routinely holds a question only another event asked, and
  // printing `custom:player:asal_sekolah` raw in the field list reads as a bug.
  const labelOf = (key: string) => fieldLabel(fieldDefs, key);

  // The printed side of the selected QR, squared off the shorter side exactly
  // as the renderer does. Zero when the selection is not a QR; the branch that
  // reads it is already behind that check.
  const qrSideMm = Math.min(
    ((active?.w ?? 0) / 100) * widthMm,
    ((active?.h ?? 0) / 100) * heightMm
  );

  /*
   * The selected text field's box and the size its sample would actually print
   * at, both in MILLIMETRES.
   *
   * Millimetres, not percent, for the same reason `size` and the QR figure are:
   * this is the number an organizer can hold a ruler against on a test print,
   * and it is the only honest way to report a preview whose font is not the
   * font the renderer draws with (see lib/fit-text.ts). 14% of a CR80 card and
   * 14% of an A6 are very different plates.
   */
  const textBoxMm = ((active?.h ?? 0) / 100) * heightMm;

  const fittedMm =
    active && scale > 0
      ? fitFontSize(
          (() => {
            const text = SAMPLE[active.key] ?? labelOf(active.key);
            return active.uppercase ? text.toUpperCase() : text;
          })(),
          {
            basePx: (active.size ?? 0) * scale,
            maxWidthPx: (((active.w ?? active.wrap) ?? 100) / 100) * canvasWidth,
            maxHeightPx: (textBoxMm / heightMm) * canvasHeight,
            bold: active.bold,
          }
        ) / scale
      : (active?.size ?? 0);

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

            // `qr` shares the photo's geometry — a box anchored at its top-left
            // and resized from the far corner — because a QR is a square drawn
            // into an area, not a string hanging off an alignment point. Only
            // what goes *inside* the box differs, which is why this branch is
            // shared rather than copied.
            if (field.key === "photo" || field.key === "qr") {
              const isQr = field.key === "qr";
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
                    {isQr ? (
                      // Squared off the shorter side, exactly as the renderer
                      // does: w != h leaves slack rather than stretching the
                      // code, so the preview must not show it filling a
                      // rectangle it will never fill.
                      <QrCode
                        style={{ width: Math.min(boxW, boxH) * 0.8, height: Math.min(boxW, boxH) * 0.8 }}
                      />
                    ) : (
                      <User className="h-1/3 w-1/3" />
                    )}
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
            // Absent means `top`, matching IdCardService::drawText(). The two
            // readings have to agree or the canvas shows a layout the printer
            // will not produce.
            const valign = field.valign ?? "top";

            // x is the point the text aligns *to*, the same as the renderer and
            // the same as the certificate editor, so both read alike. y anchors
            // the wrapped BLOCK the same way, which is why the vertical shift
            // is a translate rather than a different `top` — the block's height
            // is whatever the text wrapped to, and only the browser knows it.
            const transform = [
              align === "left" ? "" : align === "right" ? "translateX(-100%)" : "translateX(-50%)",
              valign === "top" ? "" : valign === "bottom" ? "translateY(-100%)" : "translateY(-50%)",
            ]
              .filter(Boolean)
              .join(" ") || "none";

            const sample = SAMPLE[field.key] ?? labelOf(field.key);

            // `w` where the field has one, `wrap` otherwise — the same
            // precedence the renderer applies, so a long name breaks in the same
            // place on screen as on paper.
            const wrapPx = (((field.w ?? field.wrap) ?? 100) / 100) * canvasWidth;
            const boxPx = ((field.h ?? 0) / 100) * canvasHeight;

            // Mirrors IdCardService::fittedSize(). Zero box = no shrinking,
            // which is what every template written before `h` existed means.
            const fittedPx = fitFontSize(field.uppercase ? sample.toUpperCase() : sample, {
              basePx: (field.size ?? 0) * scale,
              maxWidthPx: wrapPx,
              maxHeightPx: boxPx,
              bold: field.bold,
            });

            return (
              <div
                key={index}
                onPointerDown={(e) => {
                  e.preventDefault();
                  start({ index, mode: "move", ...grabOffset(e, field) });
                  setSelected(index);
                }}
                className={cn(
                  "absolute cursor-move",
                  // Dashed like the photo/QR boxes once the field HAS a box:
                  // what is outlined then is the area the text is fitted into,
                  // not the text's own extent, and a solid selection ring over
                  // it would claim the glyphs reach the edges.
                  isSelected &&
                    (boxPx > 0
                      ? "outline outline-2 outline-[var(--brand-600)]"
                      : "outline outline-2 outline-offset-2 outline-[var(--brand-600)]")
                )}
                style={{
                  left: `${field.x}%`,
                  top: `${field.y}%`,
                  transform,
                  width: `${wrapPx}px`,
                  // A field with no box is as tall as its text, exactly as
                  // before. One with a box is the box — that is what gets
                  // dragged, and what the size is fitted into.
                  ...(boxPx > 0 ? { height: `${boxPx}px` } : { maxWidth: `${wrapPx}px` }),
                  display: boxPx > 0 ? "flex" : undefined,
                  // Which edge the glyphs sit on inside the box, matching the
                  // renderer's own vertical anchor rather than always centring.
                  alignItems:
                    boxPx > 0
                      ? valign === "top"
                        ? "flex-start"
                        : valign === "bottom"
                          ? "flex-end"
                          : "center"
                      : undefined,
                }}
              >
                <span
                  className="block w-full"
                  style={{
                    fontSize: fittedPx,
                    color: field.color ?? "#111827",
                    fontWeight: field.bold ? 700 : 400,
                    textTransform: field.uppercase ? "uppercase" : "none",
                    textAlign: align,
                    lineHeight: 1.2,
                  }}
                >
                  {sample}
                </span>

                {isSelected && (
                  /* Text fields get the same corner handle as the boxes: typing
                     a percentage was the only way to change a name plate's
                     width, which is not something anyone finds. Its own
                     pointerdown stops the parent's, or every resize would start
                     a move as well. */
                  <span
                    onPointerDown={(e) => {
                      e.preventDefault();
                      e.stopPropagation();
                      start({ index, mode: "resize", dx: 0, dy: 0 });
                    }}
                    className={cn(
                      "absolute h-3 w-3 cursor-nwse-resize rounded-full border border-white bg-[var(--brand-600)]",
                      // On the corner the drag maths actually moves — pinned
                      // opposite the field's own anchor, so the handle is under
                      // the pointer rather than across the box from it.
                      align === "right" ? "-left-1.5" : "-right-1.5",
                      valign === "bottom" ? "-top-1.5" : "-bottom-1.5"
                    )}
                  />
                )}
              </div>
            );
          })}
        </div>

        <p className="mt-2 text-xs text-muted-foreground">
          Geser field ke posisinya, dan tarik sudutnya untuk mengubah ukuran kotak — termasuk
          kotak teks. Nama yang terlalu panjang untuk kotaknya dikecilkan otomatis sampai muat,
          jadi &ldquo;Adib&rdquo; dan &ldquo;Ahmad Fauzi Rahmatullah&rdquo; sama-sama tetap di
          dalam platnya. Teks contoh hanya pratinjau — saat dicetak, isinya diambil dari data
          event.
        </p>

        {/* Said here, where it can still be acted on. A card without this field
            still prints and still gets a number, but nothing on the plastic
            points at its verification page — and that is only discoverable
            after a batch has gone to the printer. */}
        {!fields.some((f) => f.key === "qr") && fieldDefs.some((d) => d.key === "qr") && (
          <p className="mt-2 text-xs text-[var(--warning)]">
            Template ini belum punya field <strong>QR verifikasi</strong>. Kartunya tetap
            tercetak dan tetap dapat nomor, tapi tidak ada yang bisa dipindai untuk memastikan
            kartunya asli.
          </p>
        )}
      </div>

      <div className="flex flex-col gap-4">
        <div>
          <Label htmlFor="add-field">Tambah field</Label>
          <Select
            id="add-field"
            value=""
            onChange={(e) => add(e.target.value)}
            disabled={unusedCount === 0}
            className="mt-1.5"
          >
            <option value="">
              {unusedCount ? "— pilih field —" : "Semua field sudah dipakai"}
            </option>
            {/* Grouped, because the two halves answer different questions: the
                fixed catalogue is what every card can print, while the rest is
                what THIS event asked its entrants. Flat, a jersey-size question
                sits between "Nama" and "QR verifikasi" with nothing saying
                where it came from. */}
            {unused.map((group) => (
              <optgroup key={group.group} label={group.group}>
                {group.defs.map((def) => (
                  <option key={def.key} value={def.key}>
                    {def.label}
                  </option>
                ))}
              </optgroup>
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
                <span className="truncate">
                  {labelOf(field.key)}
                  {/* Said on the row, not only in the picker: a template
                      outlives the event it was built against, and a question
                      only one event asks prints blank on every other one. The
                      organizer can act on that — the fixed fields never can. */}
                  {isCustomFieldKey(field.key) && (
                    <span className="ml-1 text-xs text-muted-foreground">· formulir</span>
                  )}
                </span>
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

            {active.key === "photo" || active.key === "qr" ? (
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

                {active.key === "qr" ? (
                  <>
                    <p className="text-xs text-muted-foreground">
                      QR selalu digambar penuh dan bersudut tajam — sudut membulat memakan pola
                      pencari yang dibaca pemindai. Kotaknya dibuat bujur sangkar dari sisi
                      terpendek, jadi lebar dan tinggi yang berbeda hanya menyisakan ruang
                      kosong.
                    </p>

                    {/* In millimetres, not percent: 20% of an A6 card and 20% of
                        a CR80 card are very different codes, and the mm figure
                        is the one an organizer can hold a ruler against — the
                        same reason `size` is in mm. */}
                    <p
                      className={cn(
                        "text-xs",
                        qrSideMm < QR_MIN_MM ? "text-[var(--danger)]" : "text-muted-foreground"
                      )}
                    >
                      Ukuran cetak QR: {qrSideMm.toFixed(1)} mm.
                      {qrSideMm < QR_MIN_MM
                        ? ` Di bawah ~${QR_MIN_MM} mm, kamera ponsel sering gagal memindainya dari jarak lengan — uji cetak dulu sebelum memakai ukuran ini.`
                        : " Cukup untuk dipindai dari jarak lengan."}
                    </p>
                  </>
                ) : (
                  <>
                    <div>
                      <Label htmlFor="f-fit">Penyesuaian</Label>
                      <Select
                        id="f-fit"
                        value={active.fit ?? "cover"}
                        onChange={(e) =>
                          update(selected, { fit: e.target.value as IdCardField["fit"] })
                        }
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
                      <p className="mt-1 text-xs text-muted-foreground">
                        0 = kotak, 50 = lingkaran.
                      </p>
                    </div>
                  </>
                )}
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

                <div className="grid grid-cols-2 gap-2">
                  <div>
                    <Label htmlFor="f-tw">Lebar kotak (%)</Label>
                    <Input
                      id="f-tw"
                      type="number"
                      min={1}
                      max={100}
                      value={active.w ?? active.wrap ?? 100}
                      onChange={(e) => update(selected, { w: Number(e.target.value) })}
                      className="mt-1"
                    />
                  </div>
                  <div>
                    <Label htmlFor="f-th">Tinggi kotak (%)</Label>
                    <Input
                      id="f-th"
                      type="number"
                      min={0}
                      max={100}
                      value={active.h ?? 0}
                      onChange={(e) => update(selected, { h: Number(e.target.value) })}
                      className="mt-1"
                    />
                  </div>
                </div>

                {/* The whole point of the height, said where it can be acted on
                    — and the 0 case said too, because a field without a height
                    behaves exactly as this editor did before and an organizer
                    who typed 0 would otherwise just see the shrinking stop. */}
                <p className="-mt-1 text-xs text-muted-foreground">
                  {textBoxMm > 0 ? (
                    <>
                      Teks yang tidak muat dikecilkan otomatis sampai masuk kotak setinggi{" "}
                      {textBoxMm.toFixed(1)} mm — nama panjang turun ukurannya, nama pendek tetap{" "}
                      {(active.size ?? 0).toFixed(1)} mm.
                      {fittedMm < (active.size ?? 0) - 0.05 && (
                        <>
                          {" "}
                          Contoh di kanvas sekarang tercetak sekitar{" "}
                          <strong>{fittedMm.toFixed(1)} mm</strong>.
                        </>
                      )}
                    </>
                  ) : (
                    <>
                      Tinggi 0 = tanpa batas: teks panjang akan terbelah beberapa baris dan tumbuh
                      keluar platnya. Isi tingginya kalau kotaknya punya batas di desain.
                    </>
                  )}
                </p>

                <div className="grid grid-cols-2 gap-2">
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
                    <Label htmlFor="f-valign">Vertikal</Label>
                    <Select
                      id="f-valign"
                      value={active.valign ?? "top"}
                      onChange={(e) =>
                        update(selected, { valign: e.target.value as IdCardField["valign"] })
                      }
                      className="mt-1"
                    >
                      <option value="top">Atas</option>
                      <option value="middle">Tengah</option>
                      <option value="bottom">Bawah</option>
                    </Select>
                  </div>
                </div>

                <p className="-mt-1 text-xs text-muted-foreground">
                  Titik X dan Y adalah tempat teks ditambatkan, dan kotaknya tumbuh dari sana —
                  jadi perataan juga menentukan ke arah mana sudut tarik bergerak. Pilih
                  &ldquo;Tengah&rdquo; untuk kotak nama: dengan &ldquo;Atas&rdquo;, nama satu kata
                  duduk tinggi di platnya sementara nama dua baris turun ke bawah.
                </p>

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
