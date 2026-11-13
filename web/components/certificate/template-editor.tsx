"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { Trash2 } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { fitFontSize } from "@/lib/fit-text";
import { fieldLabel, groupFields, isCustomFieldKey } from "@/lib/template-fields";
import { cn } from "@/lib/utils";
import type { CertificateField, CertificateFieldDef } from "@/types/api";

// A4 in points — the same page the PDF renderer lays out on, so a field sized
// 32pt here is 32pt on paper.
const PAGE = {
  landscape: { w: 842, h: 595 },
  portrait: { w: 595, h: 842 },
} as const;

/** Sample text per field, so the canvas shows shapes instead of {{placeholders}}. */
const SAMPLE: Record<string, string> = {
  recipient_name: "Garuda FC",
  team_name: "Garuda FC",
  award_title: "Juara 1",
  event_name: "Jakarta Cup 2026",
  event_date: "14 Juni 2026",
  organization_name: "Jakarta Sports EO",
  certificate_number: "CERT-2026-07-0001",
  category_name: "Putra U-17",
  jersey_number: "10",
  position: "Penyerang",
  date_of_birth: "17 Agustus 2004",
};

/** Which part of a field the pointer grabbed. */
type Drag = { index: number; mode: "move" | "resize" };

/**
 * Places fields on the organizer's artwork by dragging.
 *
 * The preview mirrors the PDF's geometry exactly: x/y are percentages, a field's
 * `align` decides which edge of the text sits on x, and font sizes are points
 * scaled to the on-screen page — otherwise what they arrange here would not be
 * what comes out of the printer.
 */
export function TemplateEditor({
  backgroundUrl,
  orientation,
  fields,
  fieldDefs,
  onChange,
}: {
  backgroundUrl: string;
  orientation: "landscape" | "portrait";
  fields: CertificateField[];
  fieldDefs: CertificateFieldDef[];
  onChange: (fields: CertificateField[]) => void;
}) {
  const page = PAGE[orientation];
  const canvasRef = useRef<HTMLDivElement>(null);
  const [canvasWidth, setCanvasWidth] = useState(0);
  const [selected, setSelected] = useState<number | null>(null);
  const draggingRef = useRef<Drag | null>(null);

  // Points → screen pixels. Everything on the canvas is sized through this.
  const scale = canvasWidth > 0 ? canvasWidth / page.w : 0;

  useEffect(() => {
    const el = canvasRef.current;
    if (!el) return;

    const observer = new ResizeObserver(([entry]) => setCanvasWidth(entry.contentRect.width));
    observer.observe(el);
    return () => observer.disconnect();
  }, []);

  const moveTo = useCallback(
    (drag: Drag, clientX: number, clientY: number) => {
      const rect = canvasRef.current?.getBoundingClientRect();
      if (!rect) return;

      const pct = (value: number) => Math.round(Math.min(100, Math.max(0, value)) * 10) / 10;

      const x = pct(((clientX - rect.left) / rect.width) * 100);
      const y = pct(((clientY - rect.top) / rect.height) * 100);

      onChange(
        fields.map((f, i) => {
          if (i !== drag.index) return f;

          if (drag.mode === "move") return { ...f, x, y };

          // Certificate text hangs from its alignment point horizontally and
          // always grows DOWNWARDS from y — there is no vertical anchor here,
          // unlike the ID card. So the width grows both ways from a centred
          // anchor while the height only ever grows down.
          const align = f.align ?? "center";
          const sized = (value: number) => pct(Math.max(1, value));

          return {
            ...f,
            w: sized(
              align === "left"
                ? x - f.x
                : align === "right"
                  ? f.x - x
                  : 2 * Math.abs(x - f.x)
            ),
            h: sized(y - f.y),
          };
        })
      );
    },
    [fields, onChange]
  );

  useEffect(() => {
    const onMove = (e: PointerEvent) => {
      if (draggingRef.current === null) return;
      e.preventDefault();
      moveTo(draggingRef.current, e.clientX, e.clientY);
    };
    const onUp = () => {
      draggingRef.current = null;
    };

    window.addEventListener("pointermove", onMove);
    window.addEventListener("pointerup", onUp);
    return () => {
      window.removeEventListener("pointermove", onMove);
      window.removeEventListener("pointerup", onUp);
    };
  }, [moveTo]);

  const update = (index: number, patch: Partial<CertificateField>) =>
    onChange(fields.map((f, i) => (i === index ? { ...f, ...patch } : f)));

  const remove = (index: number) => {
    onChange(fields.filter((_, i) => i !== index));
    setSelected(null);
  };

  const add = (key: string) => {
    if (!key) return;
    onChange([
      ...fields,
      {
        key,
        x: 50,
        y: 50,
        size: key === "qr" ? 15 : 18,
        color: "#1f2430",
        align: "center",
        bold: false,
        uppercase: false,
        // Text fields are born with a box, and that is the point of having one:
        // without a height nothing can be shrunk, so a template laid out around
        // "Garuda FC" breaks on a club name three times as long and nobody
        // finds out until the batch has been issued. A QR is sized by `size`
        // alone, exactly as before.
        ...(key === "qr" ? {} : { w: 60, h: 12 }),
      },
    ]);
    setSelected(fields.length);
  };

  const unused = groupFields(fieldDefs.filter((def) => !fields.some((f) => f.key === def.key)));
  const unusedCount = unused.reduce((n, g) => n + g.defs.length, 0);
  const active = selected !== null ? fields[selected] : undefined;
  // Through the helper, not a lookup with the key as fallback: a template is
  // org-scoped and routinely holds a question only another event asked, and
  // printing `custom:player:asal_sekolah` raw in the field list reads as a bug.
  const labelOf = (key: string) => fieldLabel(fieldDefs, key);

  return (
    <div className="grid gap-6 lg:grid-cols-[1fr_280px]">
      <div>
        <div
          ref={canvasRef}
          className="relative w-full select-none overflow-hidden rounded-xl border border-border bg-[var(--bg-soft)]"
          style={{ aspectRatio: `${page.w} / ${page.h}` }}
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
            const isQr = field.key === "qr";
            const align = field.align ?? "center";

            // Mirrors the PDF: x is the point the text aligns *to*.
            const anchor =
              align === "left"
                ? { left: `${field.x}%`, transform: "none" }
                : align === "right"
                  ? { left: `${field.x}%`, transform: "translateX(-100%)" }
                  : { left: `${field.x}%`, transform: "translateX(-50%)" };

            const sample = SAMPLE[field.key] ?? labelOf(field.key);
            const wrapPx = (((field.w ?? field.wrap) ?? 0) / 100) * canvasWidth;
            const boxPx = ((field.h ?? 0) / 100) * canvasWidth * (page.h / page.w);

            // Mirrors CertificateService::fittedSizes(). No box = no shrinking,
            // which is what every template saved before this existed means.
            const fittedPx = fitFontSize(field.uppercase ? sample.toUpperCase() : sample, {
              basePx: field.size * scale,
              maxWidthPx: wrapPx,
              maxHeightPx: boxPx,
              bold: field.bold,
            });

            return (
              <div
                key={index}
                onPointerDown={(e) => {
                  e.preventDefault();
                  draggingRef.current = { index, mode: "move" };
                  setSelected(index);
                }}
                className={cn(
                  "absolute cursor-move outline-offset-2",
                  // Only a boxless field is nowrap: with a box, wrapping is the
                  // thing being previewed.
                  wrapPx <= 0 && "whitespace-nowrap",
                  selected === index && "outline outline-2 outline-[var(--brand-600)]"
                )}
                style={{
                  top: `${field.y}%`,
                  ...(isQr ? { left: `${field.x}%` } : anchor),
                  ...(isQr || wrapPx <= 0 ? {} : { width: `${wrapPx}px` }),
                }}
              >
                {isQr ? (
                  <span
                    className="grid place-items-center border border-dashed border-[var(--brand-600)] bg-white/70 text-[9px] font-semibold text-[var(--brand-600)]"
                    style={{ width: field.size * 3 * scale, height: field.size * 3 * scale }}
                  >
                    QR
                  </span>
                ) : (
                  <span
                    className="block w-full"
                    style={{
                      fontSize: fittedPx,
                      color: field.color ?? "#1f2430",
                      fontWeight: field.bold ? 700 : 400,
                      textTransform: field.uppercase ? "uppercase" : "none",
                      textAlign: align,
                      lineHeight: 1.2,
                    }}
                  >
                    {sample}
                  </span>
                )}

                {selected === index && !isQr && (
                  /* The corner handle, so a box can be dragged instead of typed
                     — typing a percentage was the only way, which is not
                     something anyone finds. Pinned opposite the field's own
                     horizontal anchor so it sits under the pointer; always at
                     the bottom, because certificate text only ever grows down. */
                  <span
                    onPointerDown={(e) => {
                      e.preventDefault();
                      e.stopPropagation();
                      draggingRef.current = { index, mode: "resize" };
                    }}
                    className={cn(
                      "absolute -bottom-1.5 h-3 w-3 cursor-nwse-resize rounded-full border border-white bg-[var(--brand-600)]",
                      align === "right" ? "-left-1.5" : "-right-1.5"
                    )}
                  />
                )}
              </div>
            );
          })}
        </div>

        <p className="mt-2 text-xs text-muted-foreground">
          Geser setiap field ke posisinya, dan tarik sudutnya untuk mengatur kotak teks. Nama
          yang terlalu panjang untuk kotaknya dikecilkan otomatis sampai muat, jadi
          &ldquo;Garuda FC&rdquo; dan &ldquo;Persatuan Sepak Bola Mahasiswa Yogyakarta&rdquo;
          sama-sama tetap di dalam platnya. Contoh teks di atas hanya pratinjau — saat
          digenerate, isinya diambil dari data event.
        </p>
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
            <option value="">{unusedCount ? "— pilih field —" : "Semua field sudah dipakai"}</option>
            {/* Grouped, because the two halves answer different questions: the
                fixed catalogue is what every certificate can print, while the
                rest is what THIS event asked its entrants. */}
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
                  selected === index ? "bg-[var(--tint)] text-[var(--brand-600)]" : "hover:bg-[var(--bg-soft)]"
                )}
              >
                <span className="truncate">
                  {labelOf(field.key)}
                  {/* Said on the row, not only in the picker: a template
                      outlives the event it was built against, and a question
                      only one event asks prints blank on every other one. */}
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

            <div>
              <Label htmlFor="f-size">{active.key === "qr" ? "Ukuran QR" : "Ukuran font (pt)"}</Label>
              <Input
                id="f-size"
                type="number"
                value={active.size}
                onChange={(e) => update(selected, { size: Number(e.target.value) })}
                className="mt-1"
              />
            </div>

            {active.key !== "qr" && (
              <>
                <div className="grid grid-cols-2 gap-2">
                  <div>
                    <Label htmlFor="f-tw">Lebar kotak (%)</Label>
                    <Input
                      id="f-tw"
                      type="number"
                      min={0}
                      max={100}
                      value={active.w ?? active.wrap ?? 0}
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

                {/* Both halves have to be set, so both cases are said — an
                    organizer who fills one and sees nothing change would
                    otherwise have no way to learn why. */}
                <p className="-mt-1 text-xs text-muted-foreground">
                  {(active.w ?? active.wrap ?? 0) > 0 && (active.h ?? 0) > 0 ? (
                    <>
                      Teks yang tidak muat dikecilkan otomatis sampai masuk kotak ini — nama
                      panjang turun ukurannya, nama pendek tetap {active.size} pt.
                    </>
                  ) : (
                    <>
                      Isi <strong>keduanya</strong> supaya teks panjang dikecilkan otomatis.
                      Dengan salah satu kosong, teks tidak dipotong baris dan bisa melebar keluar
                      kertas.
                    </>
                  )}
                </p>

                <div>
                  <Label htmlFor="f-align">Perataan</Label>
                  <Select
                    id="f-align"
                    value={active.align ?? "center"}
                    onChange={(e) =>
                      update(selected, { align: e.target.value as CertificateField["align"] })
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
                    value={active.color ?? "#1f2430"}
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
