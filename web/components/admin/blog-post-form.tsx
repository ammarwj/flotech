"use client";

import { useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { ArrowLeft, ExternalLink, ImageIcon, Save, Send } from "lucide-react";

import {
  createBlogPost,
  getAdminBlogCategories,
  updateBlogPost,
  type BlogPostInput,
} from "@/lib/api/blog";
import { parseApiError } from "@/lib/api/errors";
import { BLOG_STATUS_LABELS, blogStatus, fromDateTimeLocal, toDateTimeLocal } from "@/lib/blog";
import { RichTextEditor } from "@/components/shared/rich-text-editor";
import { ImageUploadField } from "@/components/shared/image-upload-field";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import type { BlogPost } from "@/types/api";

/**
 * Write and edit one article.
 *
 * The two publish buttons are the shape of the whole screen. "Simpan draf" and
 * "Terbitkan" write the same row and differ only in `published_at` — a single
 * Save plus a status dropdown was the alternative, and it makes publishing a
 * thing you do by changing a select you might not notice you changed.
 */
export function BlogPostForm({ post }: { post?: BlogPost }) {
  const router = useRouter();
  const qc = useQueryClient();

  const categories = useQuery({
    queryKey: ["admin-blog-categories"],
    queryFn: getAdminBlogCategories,
  });

  const [form, setForm] = useState<BlogPostInput>({
    title: post?.title ?? "",
    body: post?.body ?? "",
    slug: post?.slug ?? "",
    excerpt: post?.excerpt ?? "",
    blog_category_id: post?.blog_category_id ?? "",
    cover_url: post?.cover_url ?? "",
    author_name: post?.author_name ?? "",
    published_at: post?.published_at ?? null,
    is_featured: post?.is_featured ?? false,
  });
  const [uploading, setUploading] = useState(false);

  const state = blogStatus({ published_at: form.published_at ?? null });

  const patch = (next: Partial<BlogPostInput>) => setForm((f) => ({ ...f, ...next }));

  const save = useMutation({
    /**
     * `publishedAt === undefined` means "leave the schedule alone" — that is
     * the plain Save on an article that is already live. The two buttons pass
     * an explicit value instead, which is also why null has to survive the
     * trip: it is how an article goes back to being a draft.
     */
    mutationFn: async (publishedAt?: string | null) => {
      const payload: BlogPostInput = {
        ...form,
        // Blank strings mean "derive it" / "no value" on the API, but only
        // null carries that; "" would be stored as an empty slug.
        slug: form.slug || null,
        excerpt: form.excerpt || null,
        cover_url: form.cover_url || null,
        author_name: form.author_name || null,
        blog_category_id: form.blog_category_id || null,
        published_at: publishedAt === undefined ? (form.published_at ?? null) : publishedAt,
      };

      return post ? updateBlogPost(post.id, payload) : createBlogPost(payload);
    },
    onSuccess: (saved) => {
      toast.success(post ? "Artikel disimpan." : "Artikel dibuat.");
      qc.invalidateQueries({ queryKey: ["admin-blog-posts"] });
      qc.invalidateQueries({ queryKey: ["admin-blog-post", saved.id] });

      if (!post) {
        router.replace(`/admin/blog/${saved.id}`);
        return;
      }

      // Keep the form in step with what the server derived — slug, excerpt and
      // reading time are all computed there.
      patch({
        slug: saved.slug,
        excerpt: saved.excerpt,
        published_at: saved.published_at,
      });
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal menyimpan artikel.").message),
  });

  // Field errors inline, the general message as a toast — the convention the
  // rest of the app follows (see EventForm).
  const fieldErrors = save.error ? parseApiError(save.error, "").fieldErrors : {};
  const err = (field: string) => fieldErrors[field];
  const busy = save.isPending || uploading;

  return (
    <form
      onSubmit={(e) => {
        e.preventDefault();
        save.mutate(undefined);
      }}
    >
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <Link
            href="/admin/blog"
            className="inline-flex items-center gap-1.5 text-sm font-semibold text-muted-foreground transition-colors hover:text-[var(--brand-600)]"
          >
            <ArrowLeft className="h-4 w-4" />
            Semua artikel
          </Link>
          <h1 className="mt-2 text-2xl font-bold" style={{ fontFamily: "var(--font-display)" }}>
            {post ? "Ubah Artikel" : "Tulis Artikel"}
          </h1>
          {post && (
            <div className="mt-2 flex items-center gap-2">
              <Badge
                variant={
                  state === "published" ? "success" : state === "scheduled" ? "warning" : "neutral"
                }
              >
                {BLOG_STATUS_LABELS[state]}
              </Badge>
              {state === "published" && (
                <Link
                  href={`/blog/${form.slug}`}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="inline-flex items-center gap-1 text-xs font-semibold text-[var(--brand-600)] hover:underline"
                >
                  <ExternalLink className="h-3.5 w-3.5" />
                  Lihat di situs
                </Link>
              )}
            </div>
          )}
        </div>

        <div className="flex flex-wrap gap-2">
          <Button type="submit" variant="outline" disabled={busy}>
            <Save className="h-4 w-4" />
            {save.isPending ? "Menyimpan…" : "Simpan"}
          </Button>
          {state === "published" ? (
            <Button
              type="button"
              variant="secondary"
              disabled={busy}
              onClick={() => save.mutate(null)}
            >
              Jadikan draf
            </Button>
          ) : (
            <Button
              type="button"
              disabled={busy}
              onClick={() => save.mutate(new Date().toISOString())}
            >
              <Send className="h-4 w-4" />
              Terbitkan sekarang
            </Button>
          )}
        </div>
      </div>

      <div className="mt-6 grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
        {/* Main column.
            `content-start` is load-bearing: a grid's rows stretch to fill the
            track by default, and this column sits beside a taller sidebar — so
            without it the two rows absorb the leftover height and each label
            drifts a hundred pixels above the field it names. */}
        <div className="grid content-start gap-4">
          <div className="grid gap-1.5">
            <Label htmlFor="title">Judul</Label>
            <Input
              id="title"
              value={form.title}
              onChange={(e) => patch({ title: e.target.value })}
              placeholder="mis. Cara Membuat Turnamen Pertama di flo-event"
              maxLength={255}
              required
            />
            {err("title") && <p className="text-sm text-destructive">{err("title")}</p>}
          </div>

          <div className="grid gap-1.5">
            <Label>Isi artikel</Label>
            <RichTextEditor
              value={form.body}
              onChange={(body) => patch({ body })}
              onBusyChange={setUploading}
            />
            {err("body") && <p className="text-sm text-destructive">{err("body")}</p>}
          </div>
        </div>

        {/* Sidebar */}
        <aside className="grid content-start gap-4 rounded-lg border border-border bg-card p-4">
          <div className="grid gap-1.5">
            <Label htmlFor="category">Kategori</Label>
            <Select
              id="category"
              value={form.blog_category_id ?? ""}
              onChange={(e) => patch({ blog_category_id: e.target.value })}
            >
              <option value="">Tanpa kategori</option>
              {(categories.data ?? []).map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </Select>
          </div>

          <ImageUploadField
            label="Gambar sampul"
            value={form.cover_url ?? ""}
            onChange={(cover_url) => patch({ cover_url })}
            onBusyChange={setUploading}
            folder="blog"
            maxDim={1600}
            previewClassName="aspect-[16/9] w-full"
            placeholder={<ImageIcon className="h-6 w-6 text-muted-foreground" />}
            hint="Rasio 16:9. Dipakai di kartu daftar dan sebagai gambar share."
          />

          <div className="grid gap-1.5">
            <Label htmlFor="excerpt">Ringkasan</Label>
            <Textarea
              id="excerpt"
              value={form.excerpt ?? ""}
              onChange={(e) => patch({ excerpt: e.target.value })}
              placeholder="Kosongkan untuk mengambil kalimat pertama artikel."
              maxLength={500}
              rows={4}
            />
            <p className="text-xs text-muted-foreground">
              Tampil di kartu daftar dan di hasil pencarian Google.
            </p>
            {err("excerpt") && <p className="text-sm text-destructive">{err("excerpt")}</p>}
          </div>

          <div className="grid gap-1.5">
            <Label htmlFor="slug">Slug URL</Label>
            <Input
              id="slug"
              value={form.slug ?? ""}
              onChange={(e) => patch({ slug: e.target.value })}
              placeholder="otomatis dari judul"
            />
            <p className="text-xs text-muted-foreground">
              Mengubahnya memutus tautan lama ke artikel ini.
            </p>
          </div>

          <div className="grid gap-1.5">
            <Label htmlFor="author">Penulis</Label>
            <Input
              id="author"
              value={form.author_name ?? ""}
              onChange={(e) => patch({ author_name: e.target.value })}
              placeholder="Namamu"
            />
          </div>

          <div className="grid gap-1.5">
            <Label htmlFor="published_at">Tanggal terbit</Label>
            <Input
              id="published_at"
              type="datetime-local"
              value={toDateTimeLocal(form.published_at ?? null)}
              onChange={(e) => patch({ published_at: fromDateTimeLocal(e.target.value) })}
            />
            <p className="text-xs text-muted-foreground">
              Kosong = draf. Tanggal di masa depan = artikel terbit sendiri saat tanggal itu tiba.
            </p>
          </div>

          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              className="h-4 w-4 rounded border-border"
              checked={form.is_featured ?? false}
              onChange={(e) => patch({ is_featured: e.target.checked })}
            />
            Tandai sebagai unggulan
          </label>
        </aside>
      </div>
    </form>
  );
}
