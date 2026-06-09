"use client";

import { useState } from "react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import type { FieldErrors } from "@/lib/api/errors";
import type { TicketCategoryInput } from "@/lib/api/tickets";
import type { TicketCategory } from "@/types/api";

/** Trim an ISO/datetime string down to the `YYYY-MM-DDTHH:mm` input value. */
function toLocalInput(value: string | null | undefined): string {
  if (!value) return "";
  return value.slice(0, 16);
}

export function TicketCategoryForm({
  initial,
  onSubmit,
  onCancel,
  pending,
  fieldErrors = {},
}: {
  initial?: TicketCategory | null;
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
  const [saleStart, setSaleStart] = useState(toLocalInput(initial?.sale_start));
  const [saleEnd, setSaleEnd] = useState(toLocalInput(initial?.sale_end));
  const [transferable, setTransferable] = useState(initial?.is_transferable ?? false);
  const [active, setActive] = useState(initial?.is_active ?? true);

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
      sale_start: saleStart || null,
      sale_end: saleEnd || null,
      is_transferable: transferable,
      is_active: active,
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

      <div className="grid gap-4 sm:grid-cols-2">
        <div>
          <Label htmlFor="tc-quota">Kuota (kosongkan = tak terbatas)</Label>
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
