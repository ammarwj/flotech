"use client";

import { useEffect, useState } from "react";
import { MapPin, Swords, X } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { TeamCombobox } from "./team-combobox";
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
  /**
   * Approved teams of the selected category. Used only for the "need 2 teams"
   * guard and the count — the pickers below search the server, so this array is
   * never rendered as a list (a large tournament would make it unusable).
   */
  teams: Team[];
  /** Scope for the server-side team search in the pickers. */
  orgId: string;
  eventId: string;
  categoryId: string;
  /**
   * The groups this category runs, if any. Empty for league and knockout —
   * they have no groups, so the picker is not offered at all.
   */
  groups?: string[];
  pending: boolean;
  onClose: () => void;
  onSubmit: (payload: CreateMatchPayload) => void;
}

export function ManualMatchDialog({ open, ...props }: ManualMatchDialogProps & { open: boolean }) {
  // Mounted only while open, so every open starts from an empty form.
  return open ? <Dialog {...props} /> : null;
}

function Dialog({
  teams,
  orgId,
  eventId,
  categoryId,
  groups = [],
  pending,
  onClose,
  onSubmit,
}: ManualMatchDialogProps) {
  const tz = useEventTimezone();
  const [group, setGroup] = useState("");
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
      group_name: group || null,
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
              {groups.length > 0 && (
                <div className="grid gap-1.5">
                  <Label htmlFor="manual-group" className="font-semibold">
                    Fase
                  </Label>
                  <Select
                    id="manual-group"
                    value={group}
                    onChange={(e) => {
                      setGroup(e.target.value);
                      // The old pair may not belong to the new group.
                      setHome("");
                      setAway("");
                    }}
                  >
                    <option value="">Pertandingan tambahan (di luar grup)</option>
                    {groups.map((g) => (
                      <option key={g} value={g}>
                        Fase Grup · Grup {g}
                      </option>
                    ))}
                  </Select>
                  <p className="text-xs text-muted-foreground">
                    {group
                      ? `Dihitung di klasemen Grup ${group}. Hanya tim Grup ${group} yang bisa dipilih.`
                      : "Tidak masuk klasemen grup mana pun."}
                  </p>
                </div>
              )}

              <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-1.5">
                  <Label htmlFor="manual-home" className="font-semibold">
                    Tim tuan rumah<span className="text-[var(--danger)]"> *</span>
                  </Label>
                  <TeamCombobox
                    id="manual-home"
                    orgId={orgId}
                    eventId={eventId}
                    categoryId={categoryId}
                    group={group || undefined}
                    value={home}
                    onChange={setHome}
                    excludeId={away || undefined}
                  />
                </div>
                <div className="grid gap-1.5">
                  <Label htmlFor="manual-away" className="font-semibold">
                    Tim tamu<span className="text-[var(--danger)]"> *</span>
                  </Label>
                  <TeamCombobox
                    id="manual-away"
                    orgId={orgId}
                    eventId={eventId}
                    categoryId={categoryId}
                    group={group || undefined}
                    value={away}
                    onChange={setAway}
                    excludeId={home || undefined}
                  />
                </div>
              </div>

              <div className="grid gap-1.5">
                <Label htmlFor="manual-when" className="font-semibold">
                  Tanggal & jam{" "}
                  <span className="font-normal text-muted-foreground">(opsional, {tzLabel(tz)})</span>
                </Label>
                <div className="relative">
                  {/* datetime-local draws its own picker button — a lucide
                      calendar beside it reads as two calendars. */}
                  <Input
                    id="manual-when"
                    type="datetime-local"
                    value={when}
                    onChange={(e) => setWhen(e.target.value)}
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
