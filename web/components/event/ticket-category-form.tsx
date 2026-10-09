"use client";

import { useState } from "react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { rupiah } from "@/lib/labels";
import { Textarea } from "@/components/ui/textarea";
import type { FieldErrors } from "@/lib/api/errors";
import type { TicketCategoryInput } from "@/lib/api/tickets";
import { eventDays, fromEventInput, toEventInput } from "@/lib/match-dates";
import {
  DAY_FIELD_LABELS,
  DAY_MODE_HINTS,
  DAY_MODE_LABELS,
  sellingDates,
} from "@/lib/tickets";
import type { TicketCategory, TicketDayMode } from "@/types/api";
import { DayPickerChips } from "./day-picker-chips";

export function TicketCategoryForm({
  initial,
  tz,
  eventStart,
  eventEnd,
  onSubmit,
  onCancel,
  pending,
  fieldErrors = {},
}: {
  initial?: TicketCategory | null;
  /** Event's IANA timezone — sale window is the venue's wall clock, not the organizer's browser. */
  tz: string;
  /** The event's own range: the only dates a category may sell. */
  eventStart: string | null;
  eventEnd: string | null;
  onSubmit: (payload: TicketCategoryInput) => void;
  onCancel: () => void;
  pending?: boolean;
  fieldErrors?: FieldErrors;
}) {
  const [name, setName] = useState(initial?.name ?? "");
  const [price, setPrice] = useState(String(initial?.price ?? 0));
  const [quota, setQuota] = useState(initial?.quota != null ? String(initial.quota) : "");
  const [description, setDescription] = useState(initial?.description ?? "");
  const [benefits, setBenefits] = useState((initial?.benefits ?? []).join(", "));
  const [saleStart, setSaleStart] = useState(toEventInput(initial?.sale_start ?? null, tz));
  const [saleEnd, setSaleEnd] = useState(toEventInput(initial?.sale_end ?? null, tz));
  const [transferable, setTransferable] = useState(initial?.is_transferable ?? false);
  const [active, setActive] = useState(initial?.is_active ?? true);
  const [dayMode, setDayMode] = useState<TicketDayMode>(initial?.day_mode ?? "none");
  const [dates, setDates] = useState<string[]>(sellingDates(initial ?? ({} as TicketCategory)));

  const days = eventDays(eventStart, eventEnd);
  // Frozen once anything sold: the mode shapes ticket rows already in buyers'
  // hands, and a date already sold cannot be withdrawn from under them. Both
  // are refused by the server too — this is the proactive half, so the
  // organizer sees the lock instead of meeting it as a 422.
  const sold = initial?.sold ?? 0;
  const soldDates = (initial?.days ?? []).filter((d) => d.sold > 0).map((d) => d.event_date);

  function toggleDate(date: string) {
    setDates((current) =>
      current.includes(date) ? current.filter((d) => d !== date) : [...current, date].sort(),
    );
  }

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    onSubmit({
      name: name.trim(),
      price: Number(price) || 0,
      quota: quota.trim() === "" ? null : Number(quota),
      description: description.trim() || null,
      benefits: benefits
        .split(",")
        .map((b) => b.trim())
        .filter(Boolean),
      sale_start: fromEventInput(saleStart, tz),
      sale_end: fromEventInput(saleEnd, tz),
      is_transferable: transferable,
      is_active: active,
      day_mode: dayMode,
      // Sent even for `none`, where the server drops them: leaving the key out
      // would make switching a category back to `none` keep its stale days.
      dates: dayMode === "none" ? [] : dates,
    });
  }

  const err = (k: string) =>
    fieldErrors[k] ? <p className="mt-1 text-xs text-[var(--danger)]">{fieldErrors[k]}</p> : null;

  return (
    <form onSubmit={handleSubmit} className="grid gap-4">
      <div className="grid gap-4 sm:grid-cols-2">
        <div>
          <Label htmlFor="tc-name">Nama kategori</Label>
          <Input
            id="tc-name"
            value={name}
            onChange={(e) => setName(e.target.value)}
            placeholder="Reguler / VIP / Tribun"
            required
          />
          {err("name")}
        </div>
        <div>
          <Label htmlFor="tc-price">Harga (Rp)</Label>
          <Input
            id="tc-price"
            type="number"
            min={0}
            value={price}
            onChange={(e) => setPrice(e.target.value)}
            required
          />
          {err("price")}
        </div>
      </div>

      <div className="grid gap-4">
        <div>
          <Label htmlFor="tc-day-mode">Penjualan per hari</Label>
          <Select
            id="tc-day-mode"
            value={dayMode}
            onChange={(e) => setDayMode(e.target.value as TicketDayMode)}
            disabled={sold > 0}
          >
            {(Object.keys(DAY_MODE_LABELS) as TicketDayMode[]).map((mode) => (
              <option key={mode} value={mode}>
                {DAY_MODE_LABELS[mode]}
              </option>
            ))}
          </Select>
          <p className="mt-1 text-xs text-muted-foreground">
            {sold > 0
              ? "Sudah ada tiket terjual, jadi mode hari tidak bisa diubah lagi."
              : DAY_MODE_HINTS[dayMode]}
          </p>
          {err("day_mode")}
        </div>

        {dayMode !== "none" && (
          <div>
            <Label>{DAY_FIELD_LABELS[dayMode]}</Label>
            <div className="mt-2">
              <DayPickerChips
                days={days.map((date) => ({
                  date,
                  disabled: false,
                  reason: undefined,
                }))}
                selected={dates}
                onToggle={(date) =>
                  // A date somebody already holds a ticket for stays put. The
                  // chip is still live for *adding*, which is the half that
                  // makes this a lock and not a freeze.
                  soldDates.includes(date) ? undefined : toggleDate(date)
                }
              />
            </div>
            <p className="mt-1.5 text-xs text-muted-foreground">
              {days.length === 0
                ? "Atur tanggal event dulu, baru tanggal tiket bisa dipilih."
                : soldDates.length > 0
                  ? `Tanggal ${soldDates.join(", ")} sudah ada tiket terjualnya dan tidak bisa dilepas.`
                  : dayMode === "per_day"
                    ? "Pembeli memilih dari tanggal ini. Kuota di bawah berlaku per tanggal."
                    : "Pembeli dapat semua tanggal ini sekaligus, dan memakai satu kursi di tiap hari."}
            </p>
            {err("dates")}

            {/* The two modes configure identically, so the only unambiguous
                way to state the difference is to price the same basket both
                ways. An organizer who meant "terusan" and picked "harian"
                finds out here, not from a buyer billed three times. */}
            {dates.length > 1 && Number(price) > 0 && (
              <p className="mt-2 rounded-lg border border-border bg-[var(--bg-soft)] p-2.5 text-xs">
                1 orang, {dates.length} hari dipilih →{" "}
                <span className="font-semibold text-foreground">
                  {rupiah(
                    Number(price) * (dayMode === "per_day" ? dates.length : 1),
                  )}
                </span>
                <span className="text-muted-foreground">
                  {dayMode === "per_day"
                    ? ` (${rupiah(Number(price))} × ${dates.length} hari)`
                    : " (satu harga untuk semua hari)"}
                </span>
              </p>
            )}
          </div>
        )}
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        <div>
          <Label htmlFor="tc-quota">
            {dayMode === "none"
              ? "Kuota (kosongkan = tak terbatas)"
              : "Kuota per tanggal (kosongkan = tak terbatas)"}
          </Label>
          <Input
            id="tc-quota"
            type="number"
            min={1}
            value={quota}
            onChange={(e) => setQuota(e.target.value)}
            placeholder="cth. 500"
          />
          {err("quota")}
        </div>
        <div>
          <Label htmlFor="tc-benefits">Benefit (pisahkan dengan koma)</Label>
          <Input
            id="tc-benefits"
            value={benefits}
            onChange={(e) => setBenefits(e.target.value)}
            placeholder="Akses tribun, Merchandise"
          />
        </div>
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        <div>
          <Label htmlFor="tc-start">Mulai dijual</Label>
          <Input
            id="tc-start"
            type="datetime-local"
            value={saleStart}
            onChange={(e) => setSaleStart(e.target.value)}
          />
        </div>
        <div>
          <Label htmlFor="tc-end">Berakhir</Label>
          <Input
            id="tc-end"
            type="datetime-local"
            value={saleEnd}
            onChange={(e) => setSaleEnd(e.target.value)}
          />
          {err("sale_end")}
        </div>
      </div>

      <div>
        <Label htmlFor="tc-desc">Deskripsi</Label>
        <Textarea
          id="tc-desc"
          value={description}
          onChange={(e) => setDescription(e.target.value)}
          rows={2}
          placeholder="Keterangan singkat kategori tiket ini."
        />
      </div>

      <div className="flex flex-wrap gap-5">
        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" checked={active} onChange={(e) => setActive(e.target.checked)} />
          Aktif (tampil di halaman pembelian)
        </label>
        <label className="flex items-center gap-2 text-sm">
          <input
            type="checkbox"
            checked={transferable}
            onChange={(e) => setTransferable(e.target.checked)}
          />
          Bisa dipindahtangankan
        </label>
      </div>

      <div className="flex gap-2">
        <Button type="submit" disabled={pending}>
          {pending ? "Menyimpan…" : initial ? "Simpan perubahan" : "Tambah kategori"}
        </Button>
        <Button type="button" variant="outline" onClick={onCancel} disabled={pending}>
          Batal
        </Button>
      </div>
    </form>
  );
}
