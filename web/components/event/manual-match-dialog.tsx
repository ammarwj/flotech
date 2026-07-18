"use client";

import { useEffect, useState } from "react";
import { CalendarClock, MapPin, Swords, X } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import type { CreateMatchPayload } from "@/lib/api/matches";
import { fromEventInput, tzLabel } from "@/lib/match-dates";
import { useEventTimezone } from "./event-timezone";
import type { Team } from "@/types/api";

/**
 * Add a single fixture by hand, for organizers who already have their own
 * schedule instead of auto-generating one. Only approved teams of the selected
 * category can be paired; the result is entered later on the match card.
 */
interface ManualMatchDialogProps {
  /** Approved teams of the selected category. */
  teams: Team[];
  pending: boolean;
  onClose: () => void;
  onSubmit: (payload: CreateMatchPayload) => void;
}

export function ManualMatchDialog({ open, ...props }: ManualMatchDialogProps & { open: boolean }) {
  // Mounted only while open, so every open starts from an empty form.
  return open ? <Dialog {...props} /> : null;
}

function Dialog({ teams, pending, onClose, onSubmit }: ManualMatchDialogProps) {
  const tz = useEventTimezone();
  const [home, setHome] = useState("");
  const [away, setAway] = useState("");
  const [when, setWhen] = useState("");
  const [venue, setVenue] = useState("");

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => e.key === "Escape" && onClose();
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [onClose]);

  const canSave = home !== "" && away !== "" && home !== away;

  const submit = () =>
    onSubmit({
      home_team_id: home,
      away_team_id: away,
      // What the organizer typed is the venue's wall clock, not their own.
      scheduled_at: fromEventInput(when, tz),
      venue: venue.trim() || null,
    });

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" onClick={onClose}>
      <div
        role="dialog"
        aria-modal="true"
        aria-label="Tambah pertandingan manual"
        onClick={(e) => e.stopPropagation()}
        className="flex max-h-[85vh] w-full max-w-lg flex-col overflow-hidden rounded-xl border border-border bg-card shadow-[var(--shadow-lg)]"
      >
        <div className="flex items-start gap-3 border-b border-border p-5">
          <span className="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-[var(--tint)] text-[var(--brand-600)]">
            <Swords className="h-5 w-5" />
          </span>
          <div className="min-w-0 flex-1">
            <h2 className="text-base font-bold" style={{ fontFamily: "var(--font-display)" }}>
              Tambah Pertandingan
            </h2>
            <p className="mt-0.5 text-sm text-muted-foreground">
              Buat satu pertandingan sendiri. Skor dan hasil diisi belakangan di kartu
              pertandingan.
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

        <div className="grid gap-4 overflow-y-auto p-5">
          {teams.length < 2 ? (
            <p className="rounded-md border border-border bg-[var(--surface-2)] px-3 py-2 text-sm text-muted-foreground">
              Butuh minimal 2 tim yang disetujui di kategori ini untuk menambah pertandingan.
            </p>
          ) : (
            <>
              <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-1.5">
                  <Label htmlFor="manual-home" className="font-semibold">
                    Tim tuan rumah<span className="text-[var(--danger)]"> *</span>
                  </Label>
                  <Select id="manual-home" value={home} onChange={(e) => setHome(e.target.value)}>
                    <option value="">Pilih tim…</option>
                    {teams.map((t) => (
                      <option key={t.id} value={t.id} disabled={t.id === away}>
                        {t.name}
                      </option>
                    ))}
                  </Select>
                </div>
                <div className="grid gap-1.5">
                  <Label htmlFor="manual-away" className="font-semibold">
                    Tim tamu<span className="text-[var(--danger)]"> *</span>
                  </Label>
                  <Select id="manual-away" value={away} onChange={(e) => setAway(e.target.value)}>
                    <option value="">Pilih tim…</option>
                    {teams.map((t) => (
                      <option key={t.id} value={t.id} disabled={t.id === home}>
                        {t.name}
                      </option>
                    ))}
                  </Select>
                </div>
              </div>

              <div className="grid gap-1.5">
                <Label htmlFor="manual-when" className="font-semibold">
                  Tanggal & jam{" "}
                  <span className="font-normal text-muted-foreground">(opsional, {tzLabel(tz)})</span>
                </Label>
                <div className="relative">
                  <CalendarClock className="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                  <Input
                    id="manual-when"
                    type="datetime-local"
                    value={when}
                    onChange={(e) => setWhen(e.target.value)}
                    className="pl-8"
                  />
                </div>
              </div>

              <div className="grid gap-1.5">
                <Label htmlFor="manual-venue" className="font-semibold">
                  Lokasi / lapangan{" "}
                  <span className="font-normal text-muted-foreground">(opsional)</span>
                </Label>
                <div className="relative">
                  <MapPin className="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                  <Input
                    id="manual-venue"
                    value={venue}
                    onChange={(e) => setVenue(e.target.value)}
                    placeholder="mis. Lapangan A"
                    className="pl-8"
                  />
                </div>
              </div>
            </>
          )}
        </div>

        <div className="flex items-center justify-end gap-2 border-t border-border p-4">
          <Button variant="ghost" onClick={onClose} disabled={pending}>
            Batal
          </Button>
          <Button onClick={submit} disabled={pending || !canSave}>
            <Swords className="h-4 w-4" />
            {pending ? "Menyimpan…" : "Tambah"}
          </Button>
        </div>
      </div>
    </div>
  );
}
