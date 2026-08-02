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
import { rupiah } from "@/lib/labels";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import type { Plan } from "@/types/api";

const EMPTY_FORM = { name: "", slug: "", price: 0 };

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
      <p className="mt-2 text-muted-foreground">Kelola paket event dan nilai fitur per paket.</p>

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
          <Label htmlFor="pm">Harga per event</Label>
          <Input
            id="pm"
            type="number"
            min={0}
            value={form.price}
            onChange={(e) => setForm({ ...form, price: Number(e.target.value) })}
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
                  {rupiah(plan.price)}/event
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
 * One price, charged once, for one event. Nothing is derived from it — what is
 * typed here is exactly what a checkout charges.
 */
function PriceEditor({ plan, onSaved }: { plan: Plan; onSaved: () => void }) {
  const [values, setValues] = useState({
    name: plan.name,
    slug: plan.slug,
    price: plan.price,
  });

  const save = useMutation({
    mutationFn: () => updatePlan(plan.id, values),
    onSuccess: () => {
      toast.success("Harga paket berhasil disimpan.");
      onSaved();
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal menyimpan harga.").message),
  });

  return (
    <div className="mt-4 grid items-end gap-3 border-t border-border pt-4 sm:grid-cols-2 lg:grid-cols-4">
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
        <Label htmlFor={`pm-${plan.id}`}>Harga per event</Label>
        <Input
          id={`pm-${plan.id}`}
          type="number"
          min={0}
          value={values.price}
          onChange={(e) => setValues({ ...values, price: Number(e.target.value) })}
        />
      </div>
      <div className="grid gap-1.5">
        <Button size="sm" onClick={() => save.mutate()} disabled={save.isPending}>
          {save.isPending ? "Menyimpan…" : "Simpan harga"}
        </Button>
      </div>
    </div>
  );
}

/**
 * The catalog is the row set, not the admin's keyboard.
 *
 * `feature_key` used to be a free-text input, so a typo (`online_registratio`)
 * saved happily — and because this endpoint prunes keys missing from the
 * payload, it *deleted* the real key at the same time, switching the feature
 * off for every event on the plan with a success toast. Rows now come from
 * `feature_details`, which carries every definition (`value: null` for the ones
 * this plan lacks), so a key can only be one the API already knows. New keys
 * are born in Definisi Fitur, next to their label — same place the seeders put
 * them.
 */
function FeatureEditor({ plan, onSaved }: { plan: Plan; onSaved: () => void }) {
  const details = plan.feature_details ?? [];
  const [values, setValues] = useState<Record<string, string>>(() =>
    Object.fromEntries(details.map((d) => [d.key, d.value ?? ""]))
  );

  // Values whose definition is gone (typos saved by the old editor, keys the
  // catalog dropped). They render nowhere else, so they are surfaced here
  // instead of being pruned behind the admin's back.
  const orphans = Object.keys(plan.features ?? {}).filter((key) => !details.some((d) => d.key === key));

  const save = useMutation({
    mutationFn: () => {
      const features: Record<string, string> = {};
      Object.entries(values).forEach(([key, value]) => {
        // Blank = the plan does not get this feature; the API prunes it.
        if (value.trim()) features[key] = value.trim();
      });
      return syncPlanFeatures(plan.id, features);
    },
    onSuccess: () => {
      toast.success("Fitur berhasil disimpan.");
      onSaved();
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal menyimpan fitur.").message),
  });

  const setValue = (key: string, value: string) => setValues((prev) => ({ ...prev, [key]: value }));

  return (
    <div className="mt-4 border-t border-border pt-4">
      <p className="text-xs text-muted-foreground">
        Kosongkan nilainya untuk mencabut fitur dari paket ini. Key baru ditambahkan di menu{" "}
        <span className="font-medium">Definisi Fitur</span>.
      </p>

      {details.length === 0 && (
        <p className="mt-3 text-sm text-muted-foreground">
          Belum ada definisi fitur. Tambahkan dulu di menu Definisi Fitur.
        </p>
      )}

      <div className="mt-3 grid gap-2">
        {details.map((detail, i) => (
          <div key={detail.key}>
            {detail.group && detail.group !== details[i - 1]?.group && (
              <p className="mb-1.5 mt-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                {detail.group}
              </p>
            )}
            <div className="grid items-center gap-2 sm:grid-cols-2">
              <div className="min-w-0">
                <p className="truncate text-sm font-medium">{detail.label}</p>
                <p className="truncate font-mono text-xs text-muted-foreground">{detail.key}</p>
              </div>
              {detail.type === "boolean" ? (
                <Select
                  value={values[detail.key] ?? ""}
                  onChange={(e) => setValue(detail.key, e.target.value)}
                >
                  <option value="">Tidak termasuk</option>
                  <option value="true">Termasuk (true)</option>
                  <option value="false">Dimatikan (false)</option>
                </Select>
              ) : (
                <Input
                  value={values[detail.key] ?? ""}
                  onChange={(e) => setValue(detail.key, e.target.value)}
                  placeholder={
                    detail.type === "numeric" ? "angka (-1 = unlimited, kosong = tidak termasuk)" : "teks"
                  }
                />
              )}
            </div>
          </div>
        ))}
      </div>

      {orphans.length > 0 && (
        <p className="mt-3 rounded-md border border-amber-500/40 bg-amber-500/10 p-2 text-xs text-amber-700 dark:text-amber-400">
          Nilai tanpa definisi: <span className="font-mono">{orphans.join(", ")}</span>. Key ini tidak dibaca
          gating mana pun dan akan dihapus saat disimpan.
        </p>
      )}

      <div className="mt-3">
        <Button size="sm" onClick={() => save.mutate()} disabled={save.isPending}>
          {save.isPending ? "Menyimpan…" : "Simpan fitur"}
        </Button>
      </div>
    </div>
  );
}
