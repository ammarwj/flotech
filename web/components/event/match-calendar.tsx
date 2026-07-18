"use client";

import { useState } from "react";
import { ChevronLeft, ChevronRight } from "lucide-react";

import { dateKeyOf } from "@/lib/match-dates";
import { matchScoreText } from "@/lib/scoring";
import { useEventTimezone } from "./event-timezone";
import { cn } from "@/lib/utils";
import type { Match } from "@/types/api";

const WEEKDAYS = ["Sen", "Sel", "Rab", "Kam", "Jum", "Sab", "Min"];
const MONTHS = [
  "Januari", "Februari", "Maret", "April", "Mei", "Juni",
  "Juli", "Agustus", "September", "Oktober", "November", "Desember",
];

/**
 * Grid-cell key in the same "YYYY-MM-DD" shape dateKeyOf() produces, so a
 * fixture lands on the day it is played at the venue rather than the day it
 * falls on in the viewer's zone.
 */
const dayKey = (y: number, m: number, d: number) =>
  `${y}-${String(m + 1).padStart(2, "0")}-${String(d).padStart(2, "0")}`;

/** Month-grid view of fixtures, grouped by their scheduled date. */
export function MatchCalendar({ matches }: { matches: Match[] }) {
  const tz = useEventTimezone();
  const byDay = new Map<string, Match[]>();
  let earliestKey: string | null = null;

  for (const m of matches) {
    if (!m.scheduled_at) continue;
    const k = dateKeyOf(m.scheduled_at, tz);
    if (!earliestKey || k < earliestKey) earliestKey = k;
    const arr = byDay.get(k);
    if (arr) arr.push(m);
    else byDay.set(k, [m]);
  }

  // Which month to open on, read in the venue's zone like everything else.
  const todayKey = dateKeyOf(new Date().toISOString(), tz);
  const [baseYear, baseMonth] = (earliestKey ?? todayKey).split("-").map(Number);
  const [cursor, setCursor] = useState({ year: baseYear, month: baseMonth - 1 });

  const shift = (delta: number) => {
    const d = new Date(cursor.year, cursor.month + delta, 1);
    setCursor({ year: d.getFullYear(), month: d.getMonth() });
  };

  const first = new Date(cursor.year, cursor.month, 1);
  const startOffset = (first.getDay() + 6) % 7; // Monday-first
  const daysInMonth = new Date(cursor.year, cursor.month + 1, 0).getDate();

  const cells: (number | null)[] = [];
  for (let i = 0; i < startOffset; i++) cells.push(null);
  for (let d = 1; d <= daysInMonth; d++) cells.push(d);
  while (cells.length % 7 !== 0) cells.push(null);

  const unscheduled = matches.filter((m) => !m.scheduled_at).length;
  const isToday = (d: number) => dayKey(cursor.year, cursor.month, d) === todayKey;

  return (
    <div className="overflow-hidden rounded-xl border border-border">
      <div className="flex items-center justify-between border-b border-border bg-[var(--surface-2)] px-3 py-2">
        <button
          onClick={() => shift(-1)}
          className="grid h-7 w-7 place-items-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
          aria-label="Bulan sebelumnya"
        >
          <ChevronLeft className="h-4 w-4" />
        </button>
        <span className="text-sm font-bold" style={{ fontFamily: "var(--font-display)" }}>
          {MONTHS[cursor.month]} {cursor.year}
        </span>
        <button
          onClick={() => shift(1)}
          className="grid h-7 w-7 place-items-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
          aria-label="Bulan berikutnya"
        >
          <ChevronRight className="h-4 w-4" />
        </button>
      </div>

      <div className="grid grid-cols-7 bg-[var(--surface-2)] text-center text-[11px] font-semibold uppercase text-muted-foreground">
        {WEEKDAYS.map((w) => (
          <div key={w} className="border-b border-border py-1.5">
            {w}
          </div>
        ))}
      </div>

      <div className="grid grid-cols-7">
        {cells.map((d, i) => {
          const list = d ? byDay.get(dayKey(cursor.year, cursor.month, d)) ?? [] : [];
          return (
            <div
              key={i}
              className={cn(
                "min-h-[88px] border-b border-r border-border p-1.5 [&:nth-child(7n)]:border-r-0",
                !d && "bg-[color-mix(in_srgb,var(--surface-2)_50%,transparent)]"
              )}
            >
              {d && (
                <div
                  className={cn(
                    "mb-1 inline-grid h-5 min-w-5 place-items-center rounded-full px-1 text-xs font-medium",
                    isToday(d) ? "bg-[var(--brand-600)] text-white" : "text-muted-foreground"
                  )}
                >
                  {d}
                </div>
              )}
              <div className="grid gap-1">
                {list.slice(0, 3).map((m) => {
                  const sc = matchScoreText(m);
                  const done = m.home_score !== null && m.away_score !== null;
                  return (
                    <div
                      key={m.id}
                      title={`${m.home_team?.name ?? "TBD"} ${done ? sc.main : "vs"} ${m.away_team?.name ?? "TBD"}`}
                      className="truncate rounded bg-[var(--tint)] px-1.5 py-1 text-[11px] leading-tight text-[var(--brand-700)]"
                    >
                      {m.home_team?.name ?? "TBD"}{" "}
                      <span className="font-bold">{done ? sc.main : "v"}</span>{" "}
                      {m.away_team?.name ?? "TBD"}
                    </div>
                  );
                })}
                {list.length > 3 && (
                  <div className="text-[10px] text-muted-foreground">+{list.length - 3} lagi</div>
                )}
              </div>
            </div>
          );
        })}
      </div>

      {unscheduled > 0 && (
        <div className="border-t border-border px-3 py-2 text-xs text-muted-foreground">
          {unscheduled} pertandingan belum terjadwal.
        </div>
      )}
    </div>
  );
}
