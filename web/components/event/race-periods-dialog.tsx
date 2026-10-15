"use client";

import { useState } from "react";
import { useMutation } from "@tanstack/react-query";
import { toast } from "sonner";
import { Plus, Trash2 } from "lucide-react";

import { saveRacePeriods, type RacePeriodInput } from "@/lib/api/race";
import { parseApiError, type FieldErrors } from "@/lib/api/errors";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogBody,
  DialogContent,
  DialogFooter,
  DialogHeader,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import type { RacePeriod } from "@/types/api";

/** A row being edited; `_key` is a stable local id for React lists only. */
type PeriodDraft = RacePeriodInput & { _key: string };

/** "2026-01-01T00:00:00+07:00" → "2026-01-01T00:00" for datetime-local. */
function toLocalInput(iso: string): string {
  const d = new Date(iso);
  const pad = (n: number) => String(n).padStart(2, "0");

  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

/**
 * The sale windows of one fun run, edited as a set.
 *
 * A single PUT rather than per-row saves, mirroring the endpoint: the windows
 * only mean anything as a sequence, and "does this overlap the next" cannot be
 * answered a row at a time. The server is the one that refuses an overlap and
 * refuses deleting a window somebody has already entered under — not re-derived
 * here, because a second copy of either rule would drift and the drift would be
 * a form that accepts what the API rejects.
 *
 * `datetime-local` gives a wall clock with no zone, which `new Date()` reads in
 * the browser's own — so an organizer in WIB types 00:00 and sends
 * "...T00:00:00+07:00". That is exactly what the server's set-only Attribute
 * expects; a bare "Y-m-d H:i" would be stored as if it were already UTC and the
 * window would slip seven hours.
 */
export function RacePeriodsDialog({
  orgId,
  eventId,
  periods,
  onClose,
  onSaved,
}: {
  orgId: string;
  eventId: string;
  periods: RacePeriod[];
  onClose: () => void;
  onSaved: () => void;
}) {
  const [rows, setRows] = useState<PeriodDraft[]>(() =>
    periods.length > 0
      ? periods.map((p) => ({
          _key: p.id,
          id: p.id,
          name: p.name,
          starts_at: toLocalInput(p.starts_at),
          ends_at: toLocalInput(p.ends_at),
        }))
      : [{ _key: "new-0", name: "Early Bird", starts_at: "", ends_at: "" }]
  );
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});

  const saveMut = useMutation({
    mutationFn: (payload: RacePeriodInput[]) => saveRacePeriods(orgId, eventId, payload),
    onSuccess: () => {
      toast.success("Periode pendaftaran disimpan.");
      onSaved();
    },
    onError: (err) => {
      const parsed = parseApiError(err, "Gagal menyimpan periode.");
      setFieldErrors(parsed.fieldErrors);
      toast.error(parsed.message);
    },
  });

  const update = (key: string, patch: Partial<PeriodDraft>) =>
    setRows((prev) => prev.map((r) => (r._key === key ? { ...r, ...patch } : r)));

  const submit = () => {
    saveMut.mutate(
      rows.map((r) => ({
        id: r.id,
        name: r.name,
        // `new Date(...).toISOString()` would be UTC with a Z; the offset form
        // is what carries the organizer's own zone, which is the point.
        starts_at: new Date(r.starts_at).toISOString(),
        ends_at: new Date(r.ends_at).toISOString(),
      }))
    );
  };

  const incomplete = rows.some((r) => !r.name.trim() || !r.starts_at || !r.ends_at);

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader
          title="Periode pendaftaran"
          description="Tiap periode punya jendela tanggalnya sendiri, dan harganya diisi per kategori. Jendela tidak boleh tumpang tindih."
        />
        <DialogBody className="space-y-3">
          {rows.map((r, i) => (
            <div key={r._key} className="space-y-2 rounded-xl border border-border p-3">
              <div className="flex items-center gap-2">
                <div className="flex-1">
                  <Label htmlFor={`period-name-${r._key}`} className="text-xs">
                    Nama periode
                  </Label>
                  <Input
                    id={`period-name-${r._key}`}
                    value={r.name}
                    onChange={(e) => update(r._key, { name: e.target.value })}
                    placeholder="Early Bird"
                  />
                </div>
                <Button
                  size="sm"
                  variant="outline"
                  className="mt-5"
                  onClick={() => setRows((prev) => prev.filter((x) => x._key !== r._key))}
                  disabled={rows.length === 1}
                  // One window has to remain: zero periods means registration
                  // can never open, and the organizer would have to come back
                  // here to find out why.
                  title={rows.length === 1 ? "Minimal satu periode" : "Hapus periode"}
                >
                  <Trash2 className="h-4 w-4" />
                </Button>
              </div>
              <div className="grid gap-2 sm:grid-cols-2">
                <div>
                  <Label htmlFor={`period-start-${r._key}`} className="text-xs">
                    Mulai
                  </Label>
                  <Input
                    id={`period-start-${r._key}`}
                    type="datetime-local"
                    value={r.starts_at}
                    onChange={(e) => update(r._key, { starts_at: e.target.value })}
                  />
                </div>
                <div>
                  <Label htmlFor={`period-end-${r._key}`} className="text-xs">
                    Berakhir
                  </Label>
                  <Input
                    id={`period-end-${r._key}`}
                    type="datetime-local"
                    value={r.ends_at}
                    onChange={(e) => update(r._key, { ends_at: e.target.value })}
                  />
                </div>
              </div>
              {i === 0 && (
                <p className="text-xs text-muted-foreground">
                  Batasnya inklusif: periode yang berakhir 10 Jan 23:59 masih
                  menerima pendaftaran pada detik itu.
                </p>
              )}
            </div>
          ))}

          <Button
            size="sm"
            variant="outline"
            onClick={() =>
              setRows((prev) => [
                ...prev,
                { _key: `new-${Date.now()}`, name: "", starts_at: "", ends_at: "" },
              ])
            }
          >
            <Plus className="h-4 w-4" />
            Tambah periode
          </Button>

          {/* `FieldErrors` is already flattened to one string per field by
              parseApiError, so there is no array to unwrap here. This is where
              the server's overlap and "already entered under" refusals land. */}
          {fieldErrors.periods && (
            <p className="text-xs text-[var(--danger)]">{fieldErrors.periods}</p>
          )}
        </DialogBody>
        <DialogFooter>
          <Button variant="outline" onClick={onClose} disabled={saveMut.isPending}>
            Batal
          </Button>
          <Button onClick={submit} disabled={saveMut.isPending || incomplete}>
            {saveMut.isPending ? "Menyimpan…" : "Simpan"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
