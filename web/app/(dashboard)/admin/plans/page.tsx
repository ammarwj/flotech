"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  getAdminPlans,
  createPlan,
  deletePlan,
  syncPlanFeatures,
} from "@/lib/api/plans";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import type { Plan } from "@/types/api";

export default function AdminPlansPage() {
  const qc = useQueryClient();
  const plansQuery = useQuery({ queryKey: ["admin-plans"], queryFn: getAdminPlans });

  const [form, setForm] = useState({ name: "", slug: "", price_monthly: 0, price_yearly: 0 });
  const [editing, setEditing] = useState<string | null>(null);

  const invalidate = () => qc.invalidateQueries({ queryKey: ["admin-plans"] });

  const create = useMutation({
    mutationFn: () => createPlan(form),
    onSuccess: () => {
      setForm({ name: "", slug: "", price_monthly: 0, price_yearly: 0 });
      invalidate();
    },
  });

  const remove = useMutation({
    mutationFn: (id: string) => deletePlan(id),
    onSuccess: invalidate,
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
        className="mt-6 grid items-end gap-3 rounded-lg border border-border bg-card p-4 sm:grid-cols-5"
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
          <Label htmlFor="py">Harga/thn</Label>
          <Input
            id="py"
            type="number"
            value={form.price_yearly}
            onChange={(e) => setForm({ ...form, price_yearly: Number(e.target.value) })}
          />
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
                  Rp {plan.price_monthly.toLocaleString("id-ID")}/bln
                </span>
              </div>
              <div className="flex gap-2">
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

            {editing === plan.id && <FeatureEditor plan={plan} onSaved={invalidate} />}
          </div>
        ))}
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
    onSuccess: onSaved,
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
