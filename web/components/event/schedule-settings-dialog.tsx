"use client";

import { useEffect, useState } from "react";
import { CalendarClock, Sparkles, X } from "lucide-react";

import type { ScheduleOptions } from "@/lib/api/matches";
import type { SportEvent } from "@/types/api";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

function Field({ label, hint, children }: { label: string; hint?: string; children: React.ReactNode }) {
  return (
    <div className="grid gap-1.5">
      <Label className="font-semibold">{label}</Label>
      {children}
      {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
    </div>
  );
}

/**
 * Pre-generate scheduling options. The backend slot-allocator turns these into
 * a concrete date/time (and venue lane) per fixture.
 */
export function ScheduleSettingsDialog({
  event,
  open,
  hasMatches,
  pending,
  onClose,
  onSubmit,
}: {
  event: SportEvent;
  open: boolean;
  hasMatches: boolean;
  pending: boolean;
  onClose: () => void;
  onSubmit: (options: ScheduleOptions) => void;
}) {
  const [startDate, setStartDate] = useState(event.start_date ?? "");
  const [dailyStart, setDailyStart] = useState("15:00");
  const [dailyEnd, setDailyEnd] = useState("21:00");
  // The sport carries its own sensible match length (admin-configurable).
  const [duration, setDuration] = useState(String(event.sport?.default_match_minutes ?? 60));
  const [breakMin, setBreakMin] = useState("15");
  const [venues, setVenues] = useState("1");
  const [maxPerDay, setMaxPerDay] = useState("");
  const [spread, setSpread] = useState(true);

  // Close on Escape while the dialog is open.
  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => e.key === "Escape" && onClose();
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [open, onClose]);

  if (!open) return null;

  const invalidWindow = dailyEnd <= dailyStart;

  const submit = () => {
    onSubmit({
      start_date: startDate || null,
      daily_start: dailyStart,
      daily_end: dailyEnd,
      match_minutes: Number(duration) || undefined,
      break_minutes: breakMin === "" ? undefined : Number(breakMin),
      venues: Number(venues) || 1,
      max_per_day: maxPerDay === "" ? null : Number(maxPerDay),
      spread,
    });
  };

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
      onClick={onClose}
    >
      <div
        role="dialog"
        aria-modal="true"
        onClick={(e) => e.stopPropagation()}
        className="w-full max-w-lg overflow-hidden rounded-xl border border-border bg-card shadow-[var(--shadow-lg)]"
      >
        <div className="flex items-start gap-3 border-b border-border p-5">
          <span className="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-[var(--tint)] text-[var(--brand-600)]">
            <CalendarClock className="h-5 w-5" />
          </span>
          <div className="min-w-0 flex-1">
            <h2 className="text-base font-bold" style={{ fontFamily: "var(--font-display)" }}>
              Pengaturan Jadwal
            </h2>
            <p className="mt-0.5 text-sm text-muted-foreground">
              Atur jam main, durasi, dan lapangan — sistem menaruh tanggal &amp; jam otomatis.
            </p>
          </div>
          <button
            onClick={onClose}
            className="rounded-md p-1 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
            aria-label="Tutup"
          >
            <X className="h-4 w-4" />
          </button>
        </div>

        <div className="grid gap-4 p-5">
          <Field label="Mulai tanggal" hint="Hari pertandingan pertama.">
            <Input type="date" value={startDate} onChange={(e) => setStartDate(e.target.value)} />
          </Field>

          <div className="grid grid-cols-2 gap-4">
            <Field label="Jam mulai">
              <Input type="time" value={dailyStart} onChange={(e) => setDailyStart(e.target.value)} />
            </Field>
            <Field label="Jam selesai">
              <Input
                type="time"
                value={dailyEnd}
                onChange={(e) => setDailyEnd(e.target.value)}
                aria-invalid={invalidWindow}
                className={invalidWindow ? "border-destructive focus-visible:ring-destructive" : ""}
              />
            </Field>
          </div>
          {invalidWindow && (
            <p className="-mt-2 text-xs text-destructive">Jam selesai harus setelah jam mulai.</p>
          )}

          <div className="grid grid-cols-2 gap-4">
            <Field label="Durasi / match" hint="menit">
              <Input type="number" min={10} value={duration} onChange={(e) => setDuration(e.target.value)} />
            </Field>
            <Field label="Jeda antar match" hint="menit">
              <Input type="number" min={0} value={breakMin} onChange={(e) => setBreakMin(e.target.value)} />
            </Field>
          </div>

          <div className="grid grid-cols-2 gap-4">
            <Field label="Jumlah lapangan" hint="Match paralel per slot.">
              <Input type="number" min={1} value={venues} onChange={(e) => setVenues(e.target.value)} />
            </Field>
            <Field label="Maks. match / hari" hint="Kosongkan = tanpa batas.">
              <Input
                type="number"
                min={1}
                value={maxPerDay}
                placeholder="Tanpa batas"
                onChange={(e) => setMaxPerDay(e.target.value)}
              />
            </Field>
          </div>

          <label className="flex cursor-pointer items-start gap-2 text-sm">
            <input
              type="checkbox"
              className="mt-0.5 h-4 w-4 accent-[var(--brand-600)]"
              checked={spread}
              onChange={(e) => setSpread(e.target.checked)}
            />
            <span>
              <span className="font-medium">Sebar merata</span>
              <span className="block text-xs text-muted-foreground">
                Bagikan ronde sepanjang rentang tanggal event, bukan menumpuk di hari-hari awal.
              </span>
            </span>
          </label>

          {hasMatches && (
            <p className="rounded-md border border-[color-mix(in_srgb,var(--warning)_40%,transparent)] bg-[color-mix(in_srgb,var(--warning)_8%,transparent)] px-3 py-2 text-xs text-[var(--warning)]">
              Jadwal &amp; hasil yang ada akan diganti.
            </p>
          )}
        </div>

        <div className="flex items-center justify-end gap-2 border-t border-border p-4">
          <Button variant="ghost" onClick={onClose} disabled={pending}>
            Batal
          </Button>
          <Button onClick={submit} disabled={pending || invalidWindow}>
            <Sparkles className="h-4 w-4" />
            {pending ? "Membuat…" : "Generate Jadwal"}
          </Button>
        </div>
      </div>
    </div>
  );
}
