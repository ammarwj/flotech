"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { Pencil, Trash2, X } from "lucide-react";

import {
  getFeatureDefinitions,
  createFeatureDefinition,
  updateFeatureDefinition,
  deleteFeatureDefinition,
  type FeatureDefinitionInput,
} from "@/lib/api/plans";
import { parseApiError } from "@/lib/api/errors";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import type { FeatureDefinition } from "@/types/api";

const EMPTY: FeatureDefinitionInput = {
  feature_key: "",
  feature_label: "",
  feature_group: "",
  feature_type: "numeric",
  description: "",
  sort_order: 0,
};

const TYPE_LABEL: Record<FeatureDefinition["feature_type"], string> = {
  boolean: "Boolean (true/false)",
  numeric: "Numerik (limit, -1 = unlimited)",
  text: "Teks",
};

export default function AdminFeatureDefinitionsPage() {
  const qc = useQueryClient();
  const query = useQuery({ queryKey: ["feature-definitions"], queryFn: getFeatureDefinitions });

  const [form, setForm] = useState<FeatureDefinitionInput>(EMPTY);
  const [editingId, setEditingId] = useState<string | null>(null);

  const invalidate = () => qc.invalidateQueries({ queryKey: ["feature-definitions"] });

  const reset = () => {
    setForm(EMPTY);
    setEditingId(null);
  };

  const save = useMutation({
    mutationFn: () =>
      editingId
        ? updateFeatureDefinition(editingId, form)
        : createFeatureDefinition(form),
    onSuccess: () => {
      toast.success(editingId ? "Definisi fitur diperbarui." : "Definisi fitur ditambahkan.");
      reset();
      invalidate();
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal menyimpan definisi fitur.").message),
  });

  const remove = useMutation({
    mutationFn: (id: string) => deleteFeatureDefinition(id),
    onSuccess: () => {
      toast.success("Definisi fitur dihapus.");
      invalidate();
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal menghapus definisi fitur.").message),
  });

  const startEdit = (def: FeatureDefinition) => {
    setEditingId(def.id);
    setForm({
      feature_key: def.feature_key,
      feature_label: def.feature_label,
      feature_group: def.feature_group ?? "",
      feature_type: def.feature_type,
      description: def.description ?? "",
      sort_order: def.sort_order,
    });
  };

  return (
    <div>
      <h1 className="text-2xl font-bold" style={{ fontFamily: "var(--font-display)" }}>
        SaaS Admin · Definisi Fitur
      </h1>
      <p className="mt-2 text-muted-foreground">
        Katalog fitur yang bisa diberi nilai per paket (kunci, label, dan tipe).
      </p>

      {query.isError && (
        <p className="mt-4 text-sm text-destructive">
          Tidak bisa memuat definisi fitur (butuh akses Super Admin & API berjalan).
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
          <Label htmlFor="feature_key">Kunci (feature_key)</Label>
          <Input
            id="feature_key"
            placeholder="mis. max_active_events"
            value={form.feature_key}
            onChange={(e) => setForm({ ...form, feature_key: e.target.value })}
            required
          />
        </div>
        <div className="grid gap-1.5">
          <Label htmlFor="feature_label">Label</Label>
          <Input
            id="feature_label"
            placeholder="mis. Maks. event aktif"
            value={form.feature_label}
            onChange={(e) => setForm({ ...form, feature_label: e.target.value })}
            required
          />
        </div>
        <div className="grid gap-1.5">
          <Label htmlFor="feature_group">Grup</Label>
          <Input
            id="feature_group"
            placeholder="mis. Event"
            value={form.feature_group ?? ""}
            onChange={(e) => setForm({ ...form, feature_group: e.target.value })}
          />
        </div>
        <div className="grid gap-1.5">
          <Label htmlFor="feature_type">Tipe</Label>
          <select
            id="feature_type"
            className="h-9 rounded-md border border-border bg-transparent px-3 text-sm"
            value={form.feature_type}
            onChange={(e) =>
              setForm({ ...form, feature_type: e.target.value as FeatureDefinition["feature_type"] })
            }
          >
            {Object.entries(TYPE_LABEL).map(([value, label]) => (
              <option key={value} value={value}>
                {label}
              </option>
            ))}
          </select>
        </div>
        <div className="grid gap-1.5">
          <Label htmlFor="sort_order">Urutan</Label>
          <Input
            id="sort_order"
            type="number"
            min={0}
            value={form.sort_order}
            onChange={(e) => setForm({ ...form, sort_order: Number(e.target.value) })}
          />
        </div>
        <div className="grid gap-1.5">
          <Label htmlFor="description">Deskripsi</Label>
          <Input
            id="description"
            value={form.description ?? ""}
            onChange={(e) => setForm({ ...form, description: e.target.value })}
          />
        </div>
        <div className="flex gap-2 lg:col-span-3">
          <Button type="submit" disabled={save.isPending}>
            {save.isPending ? "Menyimpan…" : editingId ? "Simpan perubahan" : "Tambah definisi"}
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
          <p className="text-sm text-muted-foreground">Belum ada definisi fitur.</p>
        )}
        {query.data?.map((def) => (
          <div
            key={def.id}
            className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border bg-card p-4"
          >
            <div className="min-w-0">
              <div className="flex flex-wrap items-center gap-2">
                <span className="font-semibold" style={{ fontFamily: "var(--font-display)" }}>
                  {def.feature_label}
                </span>
                <code className="rounded bg-[var(--bg-soft)] px-1.5 py-0.5 text-xs text-muted-foreground">
                  {def.feature_key}
                </code>
                <span className="rounded-full bg-[var(--tint)] px-2 py-0.5 text-xs text-[var(--brand-600)]">
                  {def.feature_type}
                </span>
                {def.feature_group && (
                  <span className="text-xs text-muted-foreground">· {def.feature_group}</span>
                )}
              </div>
              {def.description && (
                <p className="mt-1 text-sm text-muted-foreground">{def.description}</p>
              )}
            </div>
            <div className="flex gap-2">
              <Button size="sm" variant="outline" onClick={() => startEdit(def)}>
                <Pencil className="h-4 w-4" />
                Ubah
              </Button>
              <Button
                size="sm"
                variant="destructive"
                onClick={() => remove.mutate(def.id)}
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
