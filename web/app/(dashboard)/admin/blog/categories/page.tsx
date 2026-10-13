"use client";

import { useState } from "react";
import Link from "next/link";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { ArrowLeft, Pencil, Trash2, X } from "lucide-react";

import {
  createBlogCategory,
  deleteBlogCategory,
  getAdminBlogCategories,
  updateBlogCategory,
  type BlogCategoryInput,
} from "@/lib/api/blog";
import { parseApiError } from "@/lib/api/errors";
import { useConfirm } from "@/components/shared/confirm-provider";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import type { BlogCategory } from "@/types/api";

const EMPTY: BlogCategoryInput = { name: "", slug: "", description: "", sort_order: 0 };

export default function AdminBlogCategoriesPage() {
  const qc = useQueryClient();
  const confirm = useConfirm();
  const query = useQuery({
    queryKey: ["admin-blog-categories"],
    queryFn: getAdminBlogCategories,
  });

  const [form, setForm] = useState<BlogCategoryInput>(EMPTY);
  const [editingId, setEditingId] = useState<string | null>(null);

  const invalidate = () => qc.invalidateQueries({ queryKey: ["admin-blog-categories"] });

  const reset = () => {
    setForm(EMPTY);
    setEditingId(null);
  };

  const save = useMutation({
    mutationFn: () => {
      // Blank slug means "derive it" on create and "keep the stored one" on
      // update — only null carries that, "" would be saved as an empty slug.
      const payload = { ...form, slug: form.slug || null, description: form.description || null };
      return editingId ? updateBlogCategory(editingId, payload) : createBlogCategory(payload);
    },
    onSuccess: () => {
      toast.success(editingId ? "Kategori diperbarui." : "Kategori ditambahkan.");
      reset();
      invalidate();
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal menyimpan kategori.").message),
  });

  const remove = useMutation({
    mutationFn: (id: string) => deleteBlogCategory(id),
    onSuccess: () => {
      toast.success("Kategori dihapus.");
      invalidate();
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal menghapus kategori.").message),
  });

  const askRemove = async (category: BlogCategory) => {
    const count = category.posts_count ?? 0;
    const ok = await confirm({
      title: "Hapus kategori ini?",
      description: `"${category.name}" akan dihapus.`,
      // The articles survive — the FK is nullOnDelete — and saying so is the
      // difference between a confident click and a support ticket.
      consequences:
        count > 0
          ? `${count} artikel di dalamnya tetap ada, tapi jadi tanpa kategori.`
          : "Tidak ada artikel di dalamnya.",
      confirmLabel: "Hapus kategori",
      tone: "danger",
    });
    if (ok) remove.mutate(category.id);
  };

  const startEdit = (category: BlogCategory) => {
    setEditingId(category.id);
    setForm({
      name: category.name,
      slug: category.slug,
      description: category.description ?? "",
      sort_order: category.sort_order,
    });
  };

  const fieldErrors = save.error ? parseApiError(save.error, "").fieldErrors : {};

  return (
    <div>
      <Link
        href="/admin/blog"
        className="inline-flex items-center gap-1.5 text-sm font-semibold text-muted-foreground transition-colors hover:text-primary"
      >
        <ArrowLeft className="h-4 w-4" />
        Semua artikel
      </Link>

      <h1 className="mt-2 text-2xl font-bold" style={{ fontFamily: "var(--font-display)" }}>
        SaaS Admin · Kategori Blog
      </h1>
      <p className="mt-2 text-muted-foreground">
        Filter di halaman <code className="text-xs">/blog</code>. Kategori tanpa artikel terbit
        tidak ikut tampil di sana.
      </p>

      {query.isError && (
        <p className="mt-4 text-sm text-destructive">
          Tidak bisa memuat kategori (butuh akses Super Admin &amp; API berjalan).
        </p>
      )}

      <form
        onSubmit={(e) => {
          e.preventDefault();
          save.mutate();
        }}
        className="mt-6 grid items-start gap-3 rounded-lg border border-border bg-card p-4 sm:grid-cols-2"
      >
        <div className="grid gap-1.5">
          <Label htmlFor="name">Nama</Label>
          <Input
            id="name"
            placeholder="mis. Panduan"
            value={form.name}
            onChange={(e) => setForm({ ...form, name: e.target.value })}
            maxLength={255}
            required
          />
          {fieldErrors.name && <p className="text-sm text-destructive">{fieldErrors.name}</p>}
        </div>
        <div className="grid gap-1.5">
          <Label htmlFor="slug">Slug</Label>
          <Input
            id="slug"
            placeholder="otomatis dari nama"
            value={form.slug ?? ""}
            onChange={(e) => setForm({ ...form, slug: e.target.value })}
          />
          {fieldErrors.slug && <p className="text-sm text-destructive">{fieldErrors.slug}</p>}
        </div>
        <div className="grid gap-1.5 sm:col-span-2">
          <Label htmlFor="description">Deskripsi</Label>
          <Textarea
            id="description"
            placeholder="Satu kalimat tentang isi kategori ini."
            value={form.description ?? ""}
            onChange={(e) => setForm({ ...form, description: e.target.value })}
            maxLength={500}
            rows={2}
          />
        </div>
        <div className="grid gap-1.5">
          <Label htmlFor="sort_order">Urutan</Label>
          <Input
            id="sort_order"
            type="number"
            min={0}
            value={form.sort_order ?? 0}
            onChange={(e) => setForm({ ...form, sort_order: Number(e.target.value) })}
          />
        </div>
        <div className="flex gap-2 sm:col-span-2">
          <Button type="submit" disabled={save.isPending}>
            {save.isPending ? "Menyimpan…" : editingId ? "Simpan perubahan" : "Tambah kategori"}
          </Button>
          {editingId && (
            <Button type="button" variant="ghost" onClick={reset}>
              <X className="h-4 w-4" />
              Batal
            </Button>
          )}
        </div>
      </form>

      <div className="mt-6 grid gap-2">
        {query.isLoading && <p className="text-sm text-muted-foreground">Memuat…</p>}
        {query.data?.length === 0 && (
          <p className="text-sm text-muted-foreground">Belum ada kategori.</p>
        )}
        {query.data?.map((category) => (
          <div
            key={category.id}
            className="flex flex-wrap items-start justify-between gap-3 rounded-lg border border-border bg-card p-4"
          >
            <div className="min-w-0 flex-1">
              <div className="flex flex-wrap items-center gap-2">
                <span className="font-semibold" style={{ fontFamily: "var(--font-display)" }}>
                  {category.name}
                </span>
                <code className="text-xs text-muted-foreground">/{category.slug}</code>
                <span className="rounded-full bg-[var(--bg-soft)] px-2 py-0.5 text-xs text-muted-foreground">
                  {category.posts_count ?? 0} artikel
                </span>
              </div>
              {category.description && (
                <p className="mt-1 text-sm text-muted-foreground">{category.description}</p>
              )}
            </div>
            <div className="flex shrink-0 gap-2">
              <Button size="sm" variant="outline" onClick={() => startEdit(category)}>
                <Pencil className="h-4 w-4" />
                Ubah
              </Button>
              <Button
                size="sm"
                variant="destructive"
                onClick={() => void askRemove(category)}
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
