"use client";

import { Suspense, useCallback, useEffect, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { ChevronLeft, ChevronRight, Newspaper, Search } from "lucide-react";

import { getPublicBlogCategories, getPublicBlogPosts } from "@/lib/api/blog";
import { Nav } from "@/components/landing/nav";
import { Footer } from "@/components/landing/footer";
import { BlogCard } from "@/components/blog/blog-card";
import { EmptyState } from "@/components/shared/empty-state";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { cn } from "@/lib/utils";

function BlogIndex() {
  const router = useRouter();
  const params = useSearchParams();

  const search = params.get("search") ?? "";
  const category = params.get("category") ?? "";
  const page = Math.max(1, Number(params.get("page")) || 1);

  // Typed into freely; the URL (and so the query) only catches up on a pause.
  // Same shape as the event catalog's search box.
  const [term, setTerm] = useState(search);

  const setParams = useCallback(
    (patch: Record<string, string | number | undefined>) => {
      const next = new URLSearchParams(params.toString());

      for (const [key, value] of Object.entries(patch)) {
        if (value === undefined || value === "") next.delete(key);
        else next.set(key, String(value));
      }

      const qs = next.toString();
      router.replace(qs ? `/blog?${qs}` : "/blog", { scroll: false });
    },
    [params, router]
  );

  useEffect(() => {
    if (term === search) return;
    const timer = setTimeout(() => setParams({ search: term, page: undefined }), 300);
    return () => clearTimeout(timer);
  }, [term, search, setParams]);

  const categoriesQuery = useQuery({
    queryKey: ["public-blog-categories"],
    queryFn: getPublicBlogCategories,
  });

  const postsQuery = useQuery({
    queryKey: ["public-blog", { search, category, page }],
    queryFn: () =>
      getPublicBlogPosts({
        search: search || undefined,
        category: category || undefined,
        page,
      }),
    // Keep the grid on screen while the next page loads, rather than
    // collapsing to skeletons on every keystroke.
    placeholderData: keepPreviousData,
  });

  const posts = postsQuery.data?.items;
  const meta = postsQuery.data?.meta;
  const categories = categoriesQuery.data ?? [];
  const isFiltered = Boolean(search || category);

  return (
    <>
      <Nav />

      <main>
        <section className="section-sm">
          <div className="container">
            <div className="section-head">
              <p className="eyebrow">Blog</p>
              <h1 className="section-title">Panduan & Cerita Turnamen</h1>
              <p className="section-sub">
                Praktik baik menyelenggarakan kompetisi, panduan memakai flo-event, dan kabar fitur
                terbaru.
              </p>
            </div>

            <div className="mt-8">
              <div className="relative mx-auto max-w-xl">
                <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                  value={term}
                  onChange={(e) => setTerm(e.target.value)}
                  placeholder="Cari artikel…"
                  className="pl-9"
                  aria-label="Cari artikel"
                />
              </div>

              {/* Categories come from the API already filtered to the ones with
                  published articles, so no chip here can lead to an empty grid. */}
              {categories.length > 0 && (
                <div className="pill-strip mt-5 flex items-center justify-start gap-2 overflow-x-auto pb-1 sm:justify-center">
                  <CategoryChip
                    active={category === ""}
                    onClick={() => setParams({ category: undefined, page: undefined })}
                  >
                    Semua
                  </CategoryChip>
                  {categories.map((c) => (
                    <CategoryChip
                      key={c.id}
                      active={category === c.slug}
                      onClick={() => setParams({ category: c.slug, page: undefined })}
                    >
                      {c.name}
                      <span className="ml-1.5 opacity-60">{c.posts_count}</span>
                    </CategoryChip>
                  ))}
                </div>
              )}
            </div>

            <div className="mt-8">
              {postsQuery.isLoading ? (
                <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                  {[0, 1, 2, 3, 4, 5].map((i) => (
                    <Skeleton key={i} className="h-[340px] w-full rounded-xl" />
                  ))}
                </div>
              ) : posts?.length === 0 ? (
                <EmptyState
                  icon={Newspaper}
                  title={isFiltered ? "Tidak ada artikel yang cocok" : "Belum ada artikel"}
                  description={
                    isFiltered
                      ? "Coba kata kunci lain atau pilih kategori yang berbeda."
                      : "Artikel pertama sedang disiapkan. Cek lagi nanti."
                  }
                  action={
                    isFiltered ? (
                      <Button
                        variant="outline"
                        onClick={() => {
                          setTerm("");
                          router.replace("/blog", { scroll: false });
                        }}
                      >
                        Hapus filter
                      </Button>
                    ) : undefined
                  }
                />
              ) : (
                <div
                  className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3"
                  style={{ opacity: postsQuery.isPlaceholderData ? 0.6 : 1 }}
                >
                  {posts?.map((post, i) => (
                    <BlogCard key={post.id} post={post} priority={i < 3} />
                  ))}
                </div>
              )}
            </div>

            {meta && meta.last_page > 1 && (
              <div className="mt-10 flex items-center justify-center gap-4">
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
        </section>
      </main>

      <Footer />
    </>
  );
}

function CategoryChip({
  active,
  onClick,
  children,
}: {
  active: boolean;
  onClick: () => void;
  children: React.ReactNode;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={active}
      className={cn(
        "shrink-0 rounded-full border px-4 py-1.5 text-sm font-semibold transition-colors",
        active
          ? "border-[var(--brand-600)] bg-[var(--tint)] text-[var(--brand-600)]"
          : "border-border text-[var(--text-2)] hover:border-[var(--border-strong)]"
      )}
    >
      {children}
    </button>
  );
}

export default function BlogIndexPage() {
  // useSearchParams() needs a Suspense boundary or the build fails.
  return (
    <Suspense fallback={null}>
      <BlogIndex />
    </Suspense>
  );
}
