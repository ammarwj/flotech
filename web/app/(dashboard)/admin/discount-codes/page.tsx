"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { Pencil, Trash2, X } from "lucide-react";

import {
  getAdminDiscountCodes,
  createDiscountCode,
  updateDiscountCode,
  deleteDiscountCode,
  type DiscountCodeInput,
} from "@/lib/api/discount-codes";
import { getAdminPlans } from "@/lib/api/plans";
import { parseApiError } from "@/lib/api/errors";
import { rupiah } from "@/lib/labels";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import type { DiscountCode } from "@/types/api";

const EMPTY: DiscountCodeInput = {
  code: "",
  description: "",
  discount_type: "percent",
  discount_value: 10,
  max_discount: null,
  is_active: true,
  starts_at: null,
  ends_at: null,
  max_redemptions: null,
  once_per_organization: true,
  plan_ids: [],
};

/** `<input type="datetime-local">` wants `YYYY-MM-DDTHH:mm`, not an ISO string. */
function toLocalInput(value: string | null): string {
  if (!value) return "";
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return "";
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

/**
 * Discount codes for plan checkout.
 *
 * Deleting or renaming one is safe, unlike a feature key: every order snapshots
 * the code it redeemed, so an issued invoice keeps reading what was charged and
 * nothing reads these rows back after checkout. Only future purchases change.
 */
export default function AdminDiscountCodesPage() {
  const qc = useQueryClient();
  const query = useQuery({ queryKey: ["admin-discount-codes"], queryFn: getAdminDiscountCodes });
  const plansQuery = useQuery({ queryKey: ["admin-plans"], queryFn: getAdminPlans });

  const [form, setForm] = useState<DiscountCodeInput>(EMPTY);
  const [editingId, setEditingId] = useState<string | null>(null);

  const invalidate = () => qc.invalidateQueries({ queryKey: ["admin-discount-codes"] });

  const reset = () => {
    setForm(EMPTY);
    setEditingId(null);
  };

  const save = useMutation({
    mutationFn: () => (editingId ? updateDiscountCode(editingId, form) : createDiscountCode(form)),
    onSuccess: () => {
      toast.success(editingId ? "Kode diskon diperbarui." : "Kode diskon ditambahkan.");
      reset();
      invalidate();
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal menyimpan kode diskon.").message),
  });

  const remove = useMutation({
    mutationFn: (id: string) => deleteDiscountCode(id),
    onSuccess: () => {
      toast.success("Kode diskon dihapus.");
      invalidate();
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal menghapus kode diskon.").message),
  });

  const startEdit = (c: DiscountCode) => {
    setEditingId(c.id);
    setForm({
      code: c.code,
      description: c.description ?? "",
      discount_type: c.discount_type,
      discount_value: c.discount_value,
      max_discount: c.max_discount,
      is_active: c.is_active,
      starts_at: c.starts_at,
      ends_at: c.ends_at,
      max_redemptions: c.max_redemptions,
      once_per_organization: c.once_per_organization,
      plan_ids: c.plan_ids ?? [],
    });
  };

  const togglePlan = (planId: string) => {
    const current = form.plan_ids ?? [];
    setForm({
      ...form,
      plan_ids: current.includes(planId)
        ? current.filter((id) => id !== planId)
        : [...current, planId],
    });
  };

  const planName = (id: string) => plansQuery.data?.find((p) => p.id === id)?.name ?? id;

  return (
    <div>
      <h1 className="text-2xl font-bold" style={{ fontFamily: "var(--font-display)" }}>
        SaaS Admin · Kode Diskon
      </h1>
      <p className="mt-2 text-muted-foreground">
        Potongan harga saat organizer membeli paket event. Berlaku di checkout saja — upgrade
        paket selalu ditagih selisih penuh.
      </p>

      {query.isError && (
        <p className="mt-4 text-sm text-destructive">
          Tidak bisa memuat kode diskon (butuh akses Super Admin &amp; API berjalan).
        </p>
      )}

      {/* Create / edit form */}
      <form
        onSubmit={(e) => {
          e.preventDefault();
          save.mutate();
        }}
        className="mt-6 grid items-end gap-3 rounded-lg border border-border bg-card p-4 sm:grid-cols-2 lg:grid-cols-3"
      >
        <div className="grid gap-1.5">
          <Label htmlFor="code">Kode</Label>
          <Input
            id="code"
            placeholder="mis. LAUNCH50"
            value={form.code}
            onChange={(e) => setForm({ ...form, code: e.target.value.toUpperCase() })}
            required
          />
        </div>
        <div className="grid gap-1.5">
          <Label htmlFor="description">Catatan</Label>
          <Input
            id="description"
            placeholder="mis. Promo peluncuran"
            value={form.description ?? ""}
            onChange={(e) => setForm({ ...form, description: e.target.value })}
          />
        </div>
        <div className="grid gap-1.5">
          <Label htmlFor="discount_type">Jenis</Label>
          <Select
            id="discount_type"
            value={form.discount_type}
            onChange={(e) =>
              setForm({
                ...form,
                discount_type: e.target.value as DiscountCode["discount_type"],
                // The ceiling has no meaning for a fixed code: its value already
                // *is* the ceiling.
                max_discount: e.target.value === "percent" ? form.max_discount : null,
              })
            }
          >
            <option value="percent">Persentase (%)</option>
            <option value="fixed">Nominal (Rp)</option>
          </Select>
        </div>
        <div className="grid gap-1.5">
          <Label htmlFor="discount_value">
            {form.discount_type === "percent" ? "Besar diskon (%)" : "Besar diskon (Rp)"}
          </Label>
          <Input
            id="discount_value"
            type="number"
            min={0}
            max={form.discount_type === "percent" ? 100 : undefined}
            step={form.discount_type === "percent" ? 1 : 1000}
            value={form.discount_value}
            onChange={(e) => setForm({ ...form, discount_value: Number(e.target.value) })}
            required
          />
        </div>
        {form.discount_type === "percent" && (
          <div className="grid gap-1.5">
            <Label htmlFor="max_discount">Maks. potongan (Rp)</Label>
            <Input
              id="max_discount"
              type="number"
              min={0}
              step={1000}
              placeholder="Kosong = tanpa batas"
              value={form.max_discount ?? ""}
              onChange={(e) =>
                setForm({
                  ...form,
                  max_discount: e.target.value === "" ? null : Number(e.target.value),
                })
              }
            />
          </div>
        )}
        <div className="grid gap-1.5">
          <Label htmlFor="max_redemptions">Kuota pemakaian</Label>
          <Input
            id="max_redemptions"
            type="number"
            min={1}
            placeholder="Kosong = tanpa kuota"
            value={form.max_redemptions ?? ""}
            onChange={(e) =>
              setForm({
                ...form,
                max_redemptions: e.target.value === "" ? null : Number(e.target.value),
              })
            }
          />
        </div>
        <div className="grid gap-1.5">
          <Label htmlFor="starts_at">Mulai berlaku</Label>
          <Input
            id="starts_at"
            type="datetime-local"
            value={toLocalInput(form.starts_at)}
            onChange={(e) => setForm({ ...form, starts_at: e.target.value || null })}
          />
        </div>
        <div className="grid gap-1.5">
          <Label htmlFor="ends_at">Berakhir</Label>
          <Input
            id="ends_at"
            type="datetime-local"
            value={toLocalInput(form.ends_at)}
            onChange={(e) => setForm({ ...form, ends_at: e.target.value || null })}
          />
        </div>

        <div className="grid gap-1.5 lg:col-span-3">
          <Label>Berlaku untuk paket</Label>
          <p className="text-xs text-muted-foreground">
            Tidak ada yang dicentang = berlaku untuk semua paket.
          </p>
          <div className="flex flex-wrap gap-3">
            {plansQuery.data?.map((plan) => (
              <label key={plan.id} className="flex items-center gap-2 text-sm">
                <input
                  type="checkbox"
                  className="h-4 w-4 rounded border-border"
                  checked={(form.plan_ids ?? []).includes(plan.id)}
                  onChange={() => togglePlan(plan.id)}
                />
                {plan.name}
                <span className="text-xs text-muted-foreground">{rupiah(plan.price)}</span>
              </label>
            ))}
          </div>
        </div>

        <label className="flex items-center gap-2 text-sm">
          <input
            type="checkbox"
            className="h-4 w-4 rounded border-border"
            checked={form.once_per_organization}
            onChange={(e) => setForm({ ...form, once_per_organization: e.target.checked })}
          />
          Maks. sekali per organisasi
        </label>
        <label className="flex items-center gap-2 text-sm">
          <input
            type="checkbox"
            className="h-4 w-4 rounded border-border"
            checked={form.is_active}
            onChange={(e) => setForm({ ...form, is_active: e.target.checked })}
          />
          Aktif
        </label>

        <div className="flex gap-2 lg:col-span-3">
          <Button type="submit" disabled={save.isPending}>
            {save.isPending ? "Menyimpan…" : editingId ? "Simpan perubahan" : "Tambah kode"}
          </Button>
          {editingId && (
            <Button type="button" variant="ghost" onClick={reset}>
              <X className="h-4 w-4" />
              Batal
            </Button>
          )}
        </div>
      </form>

      {/* List */}
      <div className="mt-6 grid gap-2">
        {query.isLoading && <p className="text-sm text-muted-foreground">Memuat…</p>}
        {query.data?.length === 0 && (
          <p className="text-sm text-muted-foreground">Belum ada kode diskon.</p>
        )}
        {query.data?.map((c) => (
          <div
            key={c.id}
            className="flex items-start justify-between gap-3 rounded-lg border border-border bg-card p-4"
          >
            <div className="min-w-0 flex-1">
              <div className="flex flex-wrap items-center gap-2">
                <span className="font-mono font-semibold">{c.code}</span>
                <span className="text-sm text-muted-foreground">
                  {c.discount_type === "percent"
                    ? `${c.discount_value}%${c.max_discount ? ` (maks ${rupiah(c.max_discount)})` : ""}`
                    : rupiah(c.discount_value)}
                </span>
                {!c.is_active && (
                  <span className="rounded-full bg-[var(--bg-soft)] px-2 py-0.5 text-xs text-muted-foreground">
                    Nonaktif
                  </span>
                )}
              </div>
              <p className="mt-1 text-sm text-muted-foreground">
                Terpakai {c.redeemed_count}
                {c.max_redemptions === null ? "" : ` / ${c.max_redemptions}`}
                {/* Unpaid invoices hold a slot and expire on their own, which is
                    why a quota can read as full before anyone has paid. */}
                {c.reserved_count > 0 && ` · ${c.reserved_count} menunggu pembayaran`}
                {c.once_per_organization && " · maks 1× per organisasi"}
              </p>
              <p className="mt-1 text-xs text-muted-foreground">
                {(c.plan_ids ?? []).length === 0
                  ? "Semua paket"
                  : `Paket: ${(c.plan_ids ?? []).map(planName).join(", ")}`}
                {c.starts_at && ` · mulai ${new Date(c.starts_at).toLocaleString("id-ID")}`}
                {c.ends_at && ` · sampai ${new Date(c.ends_at).toLocaleString("id-ID")}`}
              </p>
              {c.description && <p className="mt-1 text-sm">{c.description}</p>}
            </div>
            <div className="flex shrink-0 gap-2">
              <Button size="sm" variant="outline" onClick={() => startEdit(c)}>
                <Pencil className="h-4 w-4" />
                Ubah
              </Button>
              <Button
                size="sm"
                variant="destructive"
                onClick={() => remove.mutate(c.id)}
                disabled={remove.isPending}
              >
                <Trash2 className="h-4 w-4" />
                Hapus
              </Button>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
