"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";

import {
  getAdminPlans,
  createPlan,
  updatePlan,
  deletePlan,
  syncPlanFeatures,
} from "@/lib/api/plans";
import { parseApiError } from "@/lib/api/errors";
import { computeYearlyPrice } from "@/lib/plan";
import { rupiah } from "@/lib/labels";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import type { Plan } from "@/types/api";

const EMPTY_FORM = { name: "", slug: "", price_monthly: 0, yearly_discount_percent: 0 };

export default function AdminPlansPage() {
  const qc = useQueryClient();
  const plansQuery = useQuery({ queryKey: ["admin-plans"], queryFn: getAdminPlans });

  const [form, setForm] = useState(EMPTY_FORM);
  const [editing, setEditing] = useState<string | null>(null);
  const [pricing, setPricing] = useState<string | null>(null);

  const invalidate = () => qc.invalidateQueries({ queryKey: ["admin-plans"] });

  const create = useMutation({
    mutationFn: () => createPlan(form),
    onSuccess: () => {
      toast.success("Paket berhasil ditambahkan.");
      setForm(EMPTY_FORM);
      invalidate();
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal menambahkan paket.").message),
  });

  const remove = useMutation({
    mutationFn: (id: string) => deletePlan(id),
    onSuccess: () => {
      toast.success("Paket berhasil dihapus.");
      invalidate();
    },
    onError: () => toast.error("Gagal menghapus paket."),
  });

  return (
    <div>
      <h1 className="text-2xl font-bold" style={{ fontFamily: "var(--font-display)" }}>
        SaaS Admin · Paket & Fitur
      </h1>
      <p className="mt-2 text-muted-foreground">Kelola paket langganan dan nilai fitur per paket.</p>

      {plansQuery.isError && (
        <p className="mt-4 text-sm text-destructive">
          Tidak bisa memuat paket (butuh akses Super Admin & API berjalan).
        </p>
      )}

      {/* Create plan */}
      <form
        onSubmit={(e) => {
          e.preventDefault();
          create.mutate();
        }}
        className="mt-6 grid items-end gap-3 rounded-lg border border-border bg-card p-4 sm:grid-cols-3 lg:grid-cols-6"
      >
        <div className="grid gap-1.5">
          <Label htmlFor="name">Nama</Label>
          <Input id="name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required />
        </div>
        <div className="grid gap-1.5">
          <Label htmlFor="slug">Slug</Label>
          <Input id="slug" value={form.slug} onChange={(e) => setForm({ ...form, slug: e.target.value })} required />
        </div>
        <div className="grid gap-1.5">
          <Label htmlFor="pm">Harga/bln</Label>
          <Input
            id="pm"
            type="number"
            value={form.price_monthly}
            onChange={(e) => setForm({ ...form, price_monthly: Number(e.target.value) })}
          />
        </div>
        <div className="grid gap-1.5">
          <Label htmlFor="yd">Diskon tahunan (%)</Label>
          <Input
            id="yd"
            type="number"
            min={0}
            max={100}
            value={form.yearly_discount_percent}
            onChange={(e) =>
              setForm({ ...form, yearly_discount_percent: Number(e.target.value) })
            }
          />
        </div>
        <div className="grid gap-1.5">
          <Label>Harga/thn</Label>
          <p className="text-sm font-semibold">
            {rupiah(computeYearlyPrice(form.price_monthly, form.yearly_discount_percent))}
          </p>
        </div>
        <Button type="submit" disabled={create.isPending}>
          {create.isPending ? "…" : "Tambah paket"}
        </Button>
      </form>

      {/* Plans list */}
      <div className="mt-6 grid gap-3">
        {plansQuery.data?.map((plan) => (
          <div key={plan.id} className="rounded-lg border border-border bg-card p-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <div>
                <span className="font-semibold" style={{ fontFamily: "var(--font-display)" }}>
                  {plan.name}
                </span>
                <span className="ml-2 text-xs text-muted-foreground">/{plan.slug}</span>
                <span className="ml-3 text-sm text-muted-foreground">
                  {rupiah(plan.price_monthly)}/bln · {rupiah(plan.price_yearly)}/thn
                  {plan.yearly_discount_percent > 0 && (
                    <span className="ml-2 font-semibold text-[var(--brand-600)]">
                      hemat {Math.round(plan.yearly_discount_percent)}%
                    </span>
                  )}
                </span>
              </div>
              <div className="flex gap-2">
                <Button
                  size="sm"
                  variant="outline"
                  onClick={() => setPricing(pricing === plan.id ? null : plan.id)}
                >
                  {pricing === plan.id ? "Tutup" : "Harga"}
                </Button>
                <Button
                  size="sm"
                  variant="outline"
                  onClick={() => setEditing(editing === plan.id ? null : plan.id)}
                >
                  {editing === plan.id ? "Tutup" : "Fitur"}
                </Button>
                <Button
                  size="sm"
                  variant="destructive"
                  onClick={() => remove.mutate(plan.id)}
                  disabled={remove.isPending}
                >
                  Hapus
                </Button>
              </div>
            </div>

            {pricing === plan.id && (
              <PriceEditor
                plan={plan}
                onSaved={() => {
                  invalidate();
                  setPricing(null);
                }}
              />
            )}
            {editing === plan.id && <FeatureEditor plan={plan} onSaved={invalidate} />}
          </div>
        ))}
      </div>
    </div>
  );
}

/**
 * The yearly price is not editable: the backend derives it from the discount, so
 * showing an input for it would invite a number that disagrees with what Midtrans
 * actually charges. We preview the derived value instead.
 */
function PriceEditor({ plan, onSaved }: { plan: Plan; onSaved: () => void }) {
  const [values, setValues] = useState({
    name: plan.name,
    slug: plan.slug,
    price_monthly: plan.price_monthly,
    yearly_discount_percent: plan.yearly_discount_percent,
  });

  const save = useMutation({
    mutationFn: () => updatePlan(plan.id, values),
    onSuccess: () => {
      toast.success("Harga paket berhasil disimpan.");
      onSaved();
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal menyimpan harga.").message),
  });

  const yearly = computeYearlyPrice(values.price_monthly, values.yearly_discount_percent);

  return (
    <div className="mt-4 grid items-end gap-3 border-t border-border pt-4 sm:grid-cols-2 lg:grid-cols-5">
      <div className="grid gap-1.5">
        <Label htmlFor={`n-${plan.id}`}>Nama</Label>
        <Input
          id={`n-${plan.id}`}
          value={values.name}
          onChange={(e) => setValues({ ...values, name: e.target.value })}
        />
      </div>
      <div className="grid gap-1.5">
        <Label htmlFor={`s-${plan.id}`}>Slug</Label>
        <Input
          id={`s-${plan.id}`}
          value={values.slug}
          onChange={(e) => setValues({ ...values, slug: e.target.value })}
        />
      </div>
      <div className="grid gap-1.5">
        <Label htmlFor={`pm-${plan.id}`}>Harga/bln</Label>
        <Input
          id={`pm-${plan.id}`}
          type="number"
          min={0}
          value={values.price_monthly}
          onChange={(e) => setValues({ ...values, price_monthly: Number(e.target.value) })}
        />
      </div>
      <div className="grid gap-1.5">
        <Label htmlFor={`yd-${plan.id}`}>Diskon tahunan (%)</Label>
        <Input
          id={`yd-${plan.id}`}
          type="number"
          min={0}
          max={100}
          value={values.yearly_discount_percent}
          onChange={(e) =>
            setValues({ ...values, yearly_discount_percent: Number(e.target.value) })
          }
        />
      </div>
      <div className="grid gap-1.5">
        <span className="text-xs text-muted-foreground">Harga/thn (otomatis)</span>
        <p className="text-sm font-semibold">{rupiah(yearly)}</p>
        <Button size="sm" onClick={() => save.mutate()} disabled={save.isPending}>
          {save.isPending ? "Menyimpan…" : "Simpan harga"}
        </Button>
      </div>
    </div>
  );
}

function FeatureEditor({ plan, onSaved }: { plan: Plan; onSaved: () => void }) {
  const [rows, setRows] = useState<Array<{ key: string; value: string }>>(
    Object.entries(plan.features ?? {}).map(([key, value]) => ({ key, value }))
  );

  const save = useMutation({
    mutationFn: () => {
      const features: Record<string, string> = {};
      rows.forEach((r) => {
        if (r.key.trim()) features[r.key.trim()] = r.value;
      });
      return syncPlanFeatures(plan.id, features);
    },
    onSuccess: () => {
      toast.success("Fitur berhasil disimpan.");
      onSaved();
    },
    onError: () => toast.error("Gagal menyimpan fitur."),
  });

  return (
    <div className="mt-4 border-t border-border pt-4">
      <div className="grid gap-2">
        {rows.map((row, i) => (
          <div key={i} className="flex gap-2">
            <Input
              placeholder="feature_key"
              value={row.key}
              onChange={(e) => setRows(rows.map((r, j) => (j === i ? { ...r, key: e.target.value } : r)))}
            />
            <Input
              placeholder="value (true / 10 / -1)"
              value={row.value}
              onChange={(e) => setRows(rows.map((r, j) => (j === i ? { ...r, value: e.target.value } : r)))}
            />
            <Button variant="ghost" size="sm" onClick={() => setRows(rows.filter((_, j) => j !== i))}>
              ✕
            </Button>
          </div>
        ))}
      </div>
      <div className="mt-3 flex gap-2">
        <Button variant="outline" size="sm" onClick={() => setRows([...rows, { key: "", value: "" }])}>
          + Baris
        </Button>
        <Button size="sm" onClick={() => save.mutate()} disabled={save.isPending}>
          {save.isPending ? "Menyimpan…" : "Simpan fitur"}
        </Button>
      </div>
    </div>
  );
}
