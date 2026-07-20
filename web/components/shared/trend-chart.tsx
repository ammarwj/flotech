"use client";

import { useId, useState } from "react";

import { angka } from "@/lib/labels";
import { cn } from "@/lib/utils";
import type { ViewTrendPoint } from "@/types/api";

const VIEWS_COLOR = "var(--brand-600)";
const VISITORS_COLOR = "var(--accent-purple)";

/** "2026-07-20" → "20 Jul" */
function shortDate(date: string): string {
  return new Intl.DateTimeFormat("id-ID", { day: "numeric", month: "short" }).format(
    new Date(`${date}T00:00:00`)
  );
}

/** "2026-07-20" → "Sen, 20 Jul 2026" */
function longDate(date: string): string {
  return new Intl.DateTimeFormat("id-ID", {
    weekday: "short",
    day: "numeric",
    month: "short",
    year: "numeric",
  }).format(new Date(`${date}T00:00:00`));
}

/**
 * Traffic over time: filled area for visits, line for unique visitors.
 *
 * Hand-rolled SVG rather than a charting library — this is 30 points and two
 * series, and no other screen needs charts yet. The viewBox is stretched with
 * preserveAspectRatio="none" so it fills any width without measuring the DOM;
 * `vector-effect="non-scaling-stroke"` keeps that from smearing the strokes.
 */
export function TrendChart({
  points,
  height = 180,
  className,
}: {
  points: ViewTrendPoint[];
  height?: number;
  className?: string;
}) {
  const gradientId = useId();
  const [hovered, setHovered] = useState<number | null>(null);

  if (points.length === 0) return null;

  const W = 300;
  const H = 100;
  const PAD = 6; // keeps the peak from touching the top edge

  // Views are always >= unique visitors, so they set the scale. The floor of 1
  // stops an all-zero window from dividing by zero.
  const peak = Math.max(...points.map((p) => p.views), 1);

  const x = (i: number) => (points.length === 1 ? W / 2 : (i / (points.length - 1)) * W);
  const y = (value: number) => H - PAD - (value / peak) * (H - PAD);

  const line = (key: "views" | "unique_visitors") =>
    points.map((p, i) => `${i === 0 ? "M" : "L"} ${x(i)} ${y(p[key])}`).join(" ");

  const area = `${line("views")} L ${x(points.length - 1)} ${H} L ${x(0)} ${H} Z`;

  const active = hovered === null ? null : points[hovered];
  const isEmpty = points.every((p) => p.views === 0);

  return (
    <div className={cn("w-full", className)}>
      <div className="relative" style={{ height }}>
        <svg
          viewBox={`0 0 ${W} ${H}`}
          preserveAspectRatio="none"
          className="h-full w-full overflow-visible"
          role="img"
          aria-label="Grafik tren kunjungan harian"
        >
          <defs>
            <linearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stopColor={VIEWS_COLOR} stopOpacity="0.28" />
              <stop offset="100%" stopColor={VIEWS_COLOR} stopOpacity="0" />
            </linearGradient>
          </defs>

          {[0, 0.5, 1].map((ratio) => (
            <line
              key={ratio}
              x1={0}
              x2={W}
              y1={PAD + ratio * (H - PAD)}
              y2={PAD + ratio * (H - PAD)}
              stroke="var(--border)"
              strokeWidth={1}
              vectorEffect="non-scaling-stroke"
            />
          ))}

          {!isEmpty && (
            <>
              <path d={area} fill={`url(#${gradientId})`} />
              <path
                d={line("views")}
                fill="none"
                stroke={VIEWS_COLOR}
                strokeWidth={2}
                strokeLinejoin="round"
                strokeLinecap="round"
                vectorEffect="non-scaling-stroke"
              />
              <path
                d={line("unique_visitors")}
                fill="none"
                stroke={VISITORS_COLOR}
                strokeWidth={2}
                strokeDasharray="4 3"
                strokeLinejoin="round"
                strokeLinecap="round"
                vectorEffect="non-scaling-stroke"
              />
            </>
          )}

          {hovered !== null && (
            <line
              x1={x(hovered)}
              x2={x(hovered)}
              y1={0}
              y2={H}
              stroke={VIEWS_COLOR}
              strokeWidth={1}
              vectorEffect="non-scaling-stroke"
            />
          )}
        </svg>

        {/* Hover targets are plain divs so the tooltip can be positioned in
            CSS pixels — inside the stretched viewBox it would be distorted. */}
        <div className="absolute inset-0 flex">
          {points.map((p, i) => (
            <button
              key={p.date}
              type="button"
              tabIndex={-1}
              aria-label={`${longDate(p.date)}: ${p.views} kunjungan`}
              className="h-full flex-1 cursor-default"
              onMouseEnter={() => setHovered(i)}
              onMouseLeave={() => setHovered(null)}
            />
          ))}
        </div>

        {active && (
          <div
            className="pointer-events-none absolute top-0 z-10 -translate-x-1/2 rounded-lg border border-border bg-[var(--bg)] px-3 py-2 text-xs shadow-lg"
            style={{
              left: `${(hovered! / Math.max(points.length - 1, 1)) * 100}%`,
              minWidth: 132,
            }}
          >
            <div className="font-semibold">{longDate(active.date)}</div>
            <div className="mt-1 flex items-center gap-1.5 text-muted-foreground">
              <span className="h-2 w-2 rounded-full" style={{ background: VIEWS_COLOR }} />
              {angka(active.views)} kunjungan
            </div>
            <div className="flex items-center gap-1.5 text-muted-foreground">
              <span className="h-2 w-2 rounded-full" style={{ background: VISITORS_COLOR }} />
              {angka(active.unique_visitors)} pengunjung
            </div>
          </div>
        )}
      </div>

      <div className="mt-2 flex justify-between text-xs text-muted-foreground">
        <span>{shortDate(points[0].date)}</span>
        {points.length > 2 && <span>{shortDate(points[Math.floor(points.length / 2)].date)}</span>}
        <span>{shortDate(points[points.length - 1].date)}</span>
      </div>

      <div className="mt-3 flex flex-wrap items-center gap-4 text-xs text-muted-foreground">
        <span className="flex items-center gap-1.5">
          <span className="h-2 w-2 rounded-full" style={{ background: VIEWS_COLOR }} />
          Kunjungan
        </span>
        <span className="flex items-center gap-1.5">
          <span className="h-2 w-2 rounded-full" style={{ background: VISITORS_COLOR }} />
          Pengunjung unik
        </span>
      </div>

      {isEmpty && (
        <p className="mt-3 text-center text-sm text-muted-foreground">
          Belum ada kunjungan dalam 30 hari terakhir.
        </p>
      )}
    </div>
  );
}
