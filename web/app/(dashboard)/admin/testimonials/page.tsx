"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { Pencil, Star, Trash2, X } from "lucide-react";

import {
  getAdminTestimonials,
  createTestimonial,
  updateTestimonial,
  deleteTestimonial,
  type TestimonialInput,
} from "@/lib/api/landing";
import { parseApiError } from "@/lib/api/errors";
import { AVATAR_PRESETS, avatarGradient } from "@/lib/landing";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import type { AvatarPreset, Testimonial } from "@/types/api";

const EMPTY: TestimonialInput = {
  quote: "",
  name: "",
  role: "",
  initials: "",
  avatar_preset: "brand",
  rating: 5,
  is_active: true,
  sort_order: 0,
};

export default function AdminTestimonialsPage() {
  const qc = useQueryClient();
  const query = useQuery({ queryKey: ["admin-testimonials"], queryFn: getAdminTestimonials });

  const [form, setForm] = useState<TestimonialInput>(EMPTY);
  const [editingId, setEditingId] = useState<string | null>(null);

  const invalidate = () => qc.invalidateQueries({ queryKey: ["admin-testimonials"] });

  const reset = () => {
    setForm(EMPTY);
    setEditingId(null);
  };

  const save = useMutation({
    mutationFn: () =>
      editingId ? updateTestimonial(editingId, form) : createTestimonial(form),
    onSuccess: () => {
      toast.success(editingId ? "Testimoni diperbarui." : "Testimoni ditambahkan.");
      reset();
      invalidate();
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal menyimpan testimoni.").message),
  });

  const remove = useMutation({
    mutationFn: (id: string) => deleteTestimonial(id),
    onSuccess: () => {
      toast.success("Testimoni dihapus.");
      invalidate();
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal menghapus testimoni.").message),
  });

  const startEdit = (t: Testimonial) => {
    setEditingId(t.id);
    setForm({
      quote: t.quote,
      name: t.name,
      role: t.role,
      initials: t.initials,
      avatar_preset: t.avatar_preset,
      rating: t.rating,
      is_active: t.is_active,
      sort_order: t.sort_order,
    });
  };

  return (
    <div>
      <h1 className="text-2xl font-bold" style={{ fontFamily: "var(--font-display)" }}>
        SaaS Admin · Testimoni
      </h1>
      <p className="mt-2 text-muted-foreground">
        Section &ldquo;Kata Mereka&rdquo; di landing page. Yang nonaktif tidak ikut tampil.
      </p>

      {query.isError && (
        <p className="mt-4 text-sm text-destructive">
          Tidak bisa memuat testimoni (butuh akses Super Admin & API berjalan).
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
          <Label htmlFor="name">Nama</Label>
          <Input
            id="name"
            placeholder="mis. Rizky Pratama"
            value={form.name}
            onChange={(e) => setForm({ ...form, name: e.target.value })}
            required
          />
        </div>
        <div className="grid gap-1.5">
          <Label htmlFor="role">Peran</Label>
          <Input
            id="role"
            placeholder="mis. Ketua Liga Futsal Bandung"
            value={form.role}
            onChange={(e) => setForm({ ...form, role: e.target.value })}
            required
          />
        </div>
        <div className="grid gap-1.5">
          <Label htmlFor="initials">Inisial</Label>
          <Input
            id="initials"
            placeholder="mis. RP"
            maxLength={4}
            value={form.initials}
            onChange={(e) => setForm({ ...form, initials: e.target.value.toUpperCase() })}
            required
          />
        </div>
        <div className="grid gap-1.5 lg:col-span-3">
          <Label htmlFor="quote">Kutipan</Label>
          <Textarea
            id="quote"
            placeholder="Apa yang mereka katakan tentang flo-event…"
            value={form.quote}
            onChange={(e) => setForm({ ...form, quote: e.target.value })}
            required
          />
        </div>
        <div className="grid gap-1.5">
          <Label htmlFor="avatar_preset">Warna avatar</Label>
          <div className="flex items-center gap-2">
            <span
              className="h-9 w-9 shrink-0 rounded-full border border-border"
              style={{ background: avatarGradient(form.avatar_preset) }}
              aria-hidden
            />
            <Select
              id="avatar_preset"
              value={form.avatar_preset}
              onChange={(e) =>
                setForm({ ...form, avatar_preset: e.target.value as AvatarPreset })
              }
            >
              {Object.entries(AVATAR_PRESETS).map(([value, preset]) => (
                <option key={value} value={value}>
                  {preset.label}
                </option>
              ))}
            </Select>
          </div>
        </div>
        <div className="grid gap-1.5">
          <Label htmlFor="rating">Rating (bintang)</Label>
          <Input
            id="rating"
            type="number"
            min={1}
            max={5}
            value={form.rating}
            onChange={(e) => setForm({ ...form, rating: Number(e.target.value) })}
          />
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
        <label className="flex items-center gap-2 text-sm">
          <input
            type="checkbox"
            className="h-4 w-4 rounded border-border"
            checked={form.is_active}
            onChange={(e) => setForm({ ...form, is_active: e.target.checked })}
          />
          Tampilkan di landing page
        </label>
        <div className="flex gap-2 lg:col-span-3">
          <Button type="submit" disabled={save.isPending}>
            {save.isPending ? "Menyimpan…" : editingId ? "Simpan perubahan" : "Tambah testimoni"}
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
          <p className="text-sm text-muted-foreground">Belum ada testimoni.</p>
        )}
        {query.data?.map((t) => (
          <div
            key={t.id}
            className="flex items-start justify-between gap-3 rounded-lg border border-border bg-card p-4"
          >
            <div className="flex min-w-0 flex-1 gap-3">
              <span
                className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-xs font-semibold text-white"
                style={{ background: avatarGradient(t.avatar_preset) }}
              >
                {t.initials}
              </span>
              <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                  <span className="font-semibold" style={{ fontFamily: "var(--font-display)" }}>
                    {t.name}
                  </span>
                  <span className="text-xs text-muted-foreground">· {t.role}</span>
                  <span className="flex items-center gap-0.5 text-xs text-muted-foreground">
                    <Star className="h-3 w-3 fill-current" />
                    {t.rating}
                  </span>
                  {!t.is_active && (
                    <span className="rounded-full bg-[var(--bg-soft)] px-2 py-0.5 text-xs text-muted-foreground">
                      Nonaktif
                    </span>
                  )}
                </div>
                <p className="mt-1 line-clamp-2 text-sm text-muted-foreground">{t.quote}</p>
              </div>
            </div>
            <div className="flex shrink-0 gap-2">
              <Button size="sm" variant="outline" onClick={() => startEdit(t)}>
                <Pencil className="h-4 w-4" />
                Ubah
              </Button>
              <Button
                size="sm"
                variant="destructive"
                onClick={() => remove.mutate(t.id)}
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
