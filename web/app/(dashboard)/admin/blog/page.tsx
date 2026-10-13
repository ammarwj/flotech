"use client";

import { Suspense, useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import {
  ChevronLeft,
  ChevronRight,
  ExternalLink,
  FolderTree,
  Newspaper,
  Pencil,
  Plus,
  Search,
  Trash2,
} from "lucide-react";

import { deleteBlogPost, getAdminBlogPosts, type AdminBlogQuery } from "@/lib/api/blog";
import { parseApiError } from "@/lib/api/errors";
import { BLOG_STATUS_LABELS, blogDateTime, blogStatus, type BlogStatus } from "@/lib/blog";
import { useConfirm } from "@/components/shared/confirm-provider";
import { EmptyState } from "@/components/shared/empty-state";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";

const STATUS_VARIANT: Record<BlogStatus, "success" | "warning" | "neutral"> = {
  published: "success",
  scheduled: "warning",
  draft: "neutral",
};

function AdminBlogList() {
  const router = useRouter();
  const params = useSearchParams();
  const qc = useQueryClient();
  const confirm = useConfirm();

  const search = params.get("search") ?? "";
  const status = (params.get("status") ?? "") as AdminBlogQuery["status"] | "";
  const page = Math.max(1, Number(params.get("page")) || 1);

  const [term, setTerm] = useState(search);

  const setParams = useCallback(
    (patch: Record<string, string | number | undefined>) => {
      const next = new URLSearchParams(params.toString());
      for (const [key, value] of Object.entries(patch)) {
        if (value === undefined || value === "") next.delete(key);
        else next.set(key, String(value));
      }
      const qs = next.toString();
      router.replace(qs ? `/admin/blog?${qs}` : "/admin/blog", { scroll: false });
    },
    [params, router]
  );

  useEffect(() => {
    if (term === search) return;
    const timer = setTimeout(() => setParams({ search: term, page: undefined }), 300);
    return () => clearTimeout(timer);
  }, [term, search, setParams]);

  const query = useQuery({
    queryKey: ["admin-blog-posts", { search, status, page }],
    queryFn: () =>
      getAdminBlogPosts({ search: search || undefined, status: status || undefined, page }),
    placeholderData: keepPreviousData,
  });

  const remove = useMutation({
    mutationFn: (id: string) => deleteBlogPost(id),
    onSuccess: () => {
      toast.success("Artikel dihapus.");
      qc.invalidateQueries({ queryKey: ["admin-blog-posts"] });
    },
    onError: (err) => toast.error(parseApiError(err, "Gagal menghapus artikel.").message),
  });

  const askRemove = async (id: string, title: string) => {
    const ok = await confirm({
      title: "Hapus artikel ini?",
      description: `"${title}" akan dihapus permanen.`,
      consequences: "Tautan yang sudah dibagikan ke artikel ini akan 404.",
      confirmLabel: "Hapus artikel",
      tone: "danger",
    });
    if (ok) remove.mutate(id);
  };

  const posts = query.data?.items;
  const meta = query.data?.meta;
  const isFiltered = Boolean(search || status);

  return (
    <div>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold" style={{ fontFamily: "var(--font-display)" }}>
            SaaS Admin · Blog
          </h1>
          <p className="mt-2 text-muted-foreground">
            Artikel di <code className="text-xs">/blog</code>. Draf dan artikel terjadwal tidak
            terlihat pengunjung.
          </p>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" asChild>
            <Link href="/admin/blog/categories">
              <FolderTree className="h-4 w-4" />
              Kategori
            </Link>
          </Button>
          <Button asChild>
            <Link href="/admin/blog/new">
              <Plus className="h-4 w-4" />
              Tulis artikel
            </Link>
          </Button>
        </div>
      </div>

      {query.isError && (
        <p className="mt-4 text-sm text-destructive">
          Tidak bisa memuat artikel (butuh akses Super Admin &amp; API berjalan).
        </p>
      )}

      <div className="mt-6 flex flex-col gap-3 sm:flex-row">
        <div className="relative flex-1">
          <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
          <Input
            value={term}
            onChange={(e) => setTerm(e.target.value)}
            placeholder="Cari judul atau ringkasan…"
            className="pl-9"
            aria-label="Cari artikel"
          />
        </div>
        <Select
          value={status ?? ""}
          onChange={(e) => setParams({ status: e.target.value, page: undefined })}
          className="sm:w-52"
          aria-label="Status artikel"
        >
          <option value="">Semua status</option>
          <option value="published">Terbit</option>
          <option value="scheduled">Terjadwal</option>
          <option value="draft">Draf</option>
        </Select>
      </div>

      <div className="mt-6 grid gap-2">
        {query.isLoading &&
          [0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-24 w-full rounded-lg" />)}

        {posts?.length === 0 && (
          <EmptyState
            icon={Newspaper}
            title={isFiltered ? "Tidak ada artikel yang cocok" : "Belum ada artikel"}
            description={
              isFiltered
                ? "Coba kata kunci atau status yang berbeda."
                : "Tulis artikel pertama supaya tautan Blog di footer tidak kosong."
            }
            action={
              isFiltered ? (
                <Button
                  variant="outline"
                  onClick={() => {
                    setTerm("");
                    router.replace("/admin/blog", { scroll: false });
                  }}
                >
                  Hapus filter
                </Button>
              ) : (
                <Button asChild>
                  <Link href="/admin/blog/new">
                    <Plus className="h-4 w-4" />
                    Tulis artikel
                  </Link>
                </Button>
              )
            }
          />
        )}

        {posts?.map((post) => {
          const state = blogStatus(post);

          return (
            <div
              key={post.id}
              className="flex flex-wrap items-start justify-between gap-3 rounded-lg border border-border bg-card p-4"
            >
              <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                  <span className="font-semibold" style={{ fontFamily: "var(--font-display)" }}>
                    {post.title}
                  </span>
                  <Badge variant={STATUS_VARIANT[state]}>{BLOG_STATUS_LABELS[state]}</Badge>
                  {post.category && <Badge variant="outline">{post.category.name}</Badge>}
                </div>
                <p className="mt-1 line-clamp-2 text-sm text-muted-foreground">{post.excerpt}</p>
                <p className="mt-2 text-xs text-muted-foreground">
                  {post.author_name} · {blogDateTime(post.published_at)} · {post.views} kali dibaca
                </p>
              </div>

              <div className="flex shrink-0 flex-wrap gap-2">
                {/* Only for live articles: the public route serves published
                    rows only, so a preview link on a draft would 404. */}
                {state === "published" && (
                  <Button size="sm" variant="ghost" asChild>
                    <Link href={`/blog/${post.slug}`} target="_blank" rel="noopener noreferrer">
                      <ExternalLink className="h-4 w-4" />
                      Lihat
                    </Link>
                  </Button>
                )}
                <Button size="sm" variant="outline" asChild>
                  <Link href={`/admin/blog/${post.id}`}>
                    <Pencil className="h-4 w-4" />
                    Ubah
                  </Link>
                </Button>
                <Button
                  size="sm"
                  variant="destructive"
                  onClick={() => void askRemove(post.id, post.title)}
                  disabled={remove.isPending}
                >
                  <Trash2 className="h-4 w-4" />
                  Hapus
                </Button>
              </div>
            </div>
          );
        })}
      </div>

      {meta && meta.last_page > 1 && (
        <div className="mt-6 flex items-center justify-center gap-4">
          <Button
            variant="outline"
            size="sm"
            disabled={page <= 1}
            onClick={() => setParams({ page: page - 1 })}
          >
            <ChevronLeft className="h-4 w-4" />
            Sebelumnya
          </Button>
          <span className="text-sm text-muted-foreground">
            Halaman {meta.page} dari {meta.last_page}
          </span>
          <Button
            variant="outline"
            size="sm"
            disabled={page >= meta.last_page}
            onClick={() => setParams({ page: page + 1 })}
          >
            Berikutnya
            <ChevronRight className="h-4 w-4" />
          </Button>
        </div>
      )}
    </div>
  );
}

export default function AdminBlogPage() {
  return (
    <Suspense fallback={null}>
      <AdminBlogList />
    </Suspense>
  );
}
