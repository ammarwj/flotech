"use client";

import { useState } from "react";
import { ShieldAlert } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import {
  Dialog,
  DialogBody,
  DialogContent,
  DialogFooter,
  DialogHeader,
  dialogConsequences,
} from "@/components/ui/dialog";
import { EVENT_STATUS_LABELS } from "@/lib/labels";
import type { AdminEvent, EventStatus } from "@/types/api";

/**
 * Paksa status sebuah event langsung, melewati `Event::TRANSITIONS` —
 * satu-satunya jalan balik untuk `ongoing` (tidak ada entri balik ke
 * `open`/`registration_closed` di state machine itu, dan itu sengaja: lihat
 * docblock `EventController::forceStatus()` di backend). Khusus super admin,
 * bukan organizer sendiri, karena pihak yang diuntungkan oleh dilewatinya
 * guard tidak boleh jadi pihak yang memutuskan guard itu layak dilewati.
 *
 * Semua status ditawarkan, bukan cuma "mundur" — menyimpan daftar target yang
 * disetujui di sini akan jadi aturan kedua yang bisa menyimpang dari
 * `Event::TRANSITIONS` di backend. Yang menahan ini dari jadi bebas sembarangan
 * adalah alasan WAJIB diisi, dan itu tercatat di ledger `event_status_overrides`.
 */
export function ForceEventStatusDialog({
  event,
  open,
  onOpenChange,
  pending,
  onSubmit,
}: {
  event: AdminEvent | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  pending: boolean;
  onSubmit: (values: { status: string; reason: string }) => void;
}) {
  const [status, setStatus] = useState<string>("");
  const [reason, setReason] = useState("");

  // Baris baru = form baru: tanpa ini, membuka event lain mewarisi status
  // tujuan dan alasan yang tadi diketik untuk event sebelumnya.
  const [seenEventId, setSeenEventId] = useState(event?.id ?? null);
  if (event && event.id !== seenEventId) {
    setSeenEventId(event.id);
    setStatus("");
    setReason("");
  }

  if (!event) return null;

  const unchanged = status === "" || status === event.status;

  return (
    <Dialog open={open} onOpenChange={(next) => !pending && onOpenChange(next)}>
      <DialogContent>
        <DialogHeader
          icon={ShieldAlert}
          tone="danger"
          title="Paksa status event"
          description={`${event.name} — status saat ini "${EVENT_STATUS_LABELS[event.status as EventStatus] ?? event.status}".`}
        />

        <DialogBody>
          <div className="grid gap-1.5">
            <Label htmlFor="force-status-target">Status tujuan</Label>
            <Select
              id="force-status-target"
              value={status}
              onChange={(e) => setStatus(e.target.value)}
            >
              <option value="">Pilih status…</option>
              {Object.entries(EVENT_STATUS_LABELS).map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </Select>
          </div>

          <div className="grid gap-1.5">
            <Label htmlFor="force-status-reason">Alasan (wajib, tercatat di ledger)</Label>
            <Textarea
              id="force-status-reason"
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              placeholder="Mis. organizer salah klik, seharusnya masih pendaftaran dibuka."
              maxLength={500}
              className="min-h-[90px]"
            />
          </div>

          <p className={dialogConsequences.danger}>
            Ini melewati aturan transisi status biasa (yang menolak
            "Berlangsung" kembali ke "Pendaftaran Dibuka"/"Pendaftaran Ditutup").
            Efek yang dibaca langsung dari status — jendela pendaftaran publik,
            pengecualian minimum penarikan dana — ikut berubah seketika begitu
            disimpan.
          </p>
        </DialogBody>

        <DialogFooter>
          <Button type="button" variant="outline" disabled={pending} onClick={() => onOpenChange(false)}>
            Batal
          </Button>
          <Button
            type="button"
            variant="destructive"
            disabled={pending || unchanged || reason.trim().length === 0}
            onClick={() => onSubmit({ status, reason: reason.trim() })}
          >
            {pending ? "Memaksa…" : "Paksa status"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
