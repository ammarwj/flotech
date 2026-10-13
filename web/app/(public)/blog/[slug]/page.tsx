import { cache } from "react";
import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { ArrowLeft, CalendarDays, Clock, User } from "lucide-react";

import { Nav } from "@/components/landing/nav";
import { Footer } from "@/components/landing/footer";
import { BlogCard } from "@/components/blog/blog-card";
import { Badge } from "@/components/ui/badge";
import { blogDate, readTimeLabel } from "@/lib/blog";
import type { ApiEnvelope, BlogArticle } from "@/types/api";

const API_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000/api/v1";

/**
 * Server-rendered, unlike the blog index: an article is the one page here
 * whose whole purpose is to be found and shared, so its text and its OG tags
 * have to exist in the HTML rather than appear after a client fetch. An
 * unknown slug answers a real 404 for the same reason the organizer profile
 * does.
 *
 * `cache()` is load-bearing. The page and generateMetadata both need the
 * article, and GET /blog/{slug} increments the view counter — two calls would
 * count every visitor twice.
 */
const fetchArticle = cache(async (slug: string): Promise<BlogArticle> => {
  const res = await fetch(`${API_URL}/blog/${encodeURIComponent(slug)}`, { cache: "no-store" });

  if (res.status === 404) notFound();
  if (!res.ok) throw new Error(`Gagal memuat artikel (${res.status})`);

  const body = (await res.json()) as ApiEnvelope<BlogArticle>;
  return body.data;
});

export async function generateMetadata({
  params,
}: {
  params: Promise<{ slug: string }>;
}): Promise<Metadata> {
  const { slug } = await params;
  const { post } = await fetchArticle(slug);

  return {
    title: `${post.title} — Blog flo-event`,
    description: post.excerpt,
    openGraph: {
      type: "article",
      title: post.title,
      description: post.excerpt,
      publishedTime: post.published_at ?? undefined,
      authors: [post.author_name],
      images: post.cover_url ? [post.cover_url] : undefined,
    },
    twitter: {
      card: post.cover_url ? "summary_large_image" : "summary",
      title: post.title,
      description: post.excerpt,
      images: post.cover_url ? [post.cover_url] : undefined,
    },
  };
}

export default async function BlogArticlePage({
  params,
}: {
  params: Promise<{ slug: string }>;
}) {
  const { slug } = await params;
  const { post, related } = await fetchArticle(slug);

  return (
    <>
      <Nav />

      <main>
        <article className="section-sm">
          <div className="container" style={{ maxWidth: 820 }}>
            <Link
              href="/blog"
              className="inline-flex items-center gap-1.5 text-sm font-semibold text-muted-foreground transition-colors hover:text-[var(--brand-600)]"
            >
              <ArrowLeft className="h-4 w-4" />
              Semua artikel
            </Link>

            <header className="mt-6">
              {post.category && (
                <Link href={`/blog?category=${post.category.slug}`}>
                  <Badge variant="info">{post.category.name}</Badge>
                </Link>
              )}
              <h1
                className="mt-3 text-3xl font-bold leading-tight sm:text-4xl"
                style={{ fontFamily: "var(--font-display)" }}
              >
                {post.title}
              </h1>
              <p className="mt-3 text-lg leading-relaxed text-muted-foreground">{post.excerpt}</p>

              <div className="mt-5 flex flex-wrap items-center gap-x-5 gap-y-2 border-y border-border py-3 text-sm text-muted-foreground">
                <span className="inline-flex items-center gap-1.5">
                  <User className="h-4 w-4" />
                  {post.author_name}
                </span>
                <span className="inline-flex items-center gap-1.5">
                  <CalendarDays className="h-4 w-4" />
                  {blogDate(post.published_at)}
                </span>
                <span className="inline-flex items-center gap-1.5">
                  <Clock className="h-4 w-4" />
                  {readTimeLabel(post.read_minutes)}
                </span>
              </div>
            </header>

            {post.cover_url && (
              // eslint-disable-next-line @next/next/no-img-element
              <img
                src={post.cover_url}
                alt=""
                className="mt-7 aspect-[16/9] w-full rounded-xl border border-border object-cover"
              />
            )}

            {/* Safe because the API sanitises on write — App\Support\HtmlSanitizer
                runs inside BlogPostService, the only thing allowed to write the
                column, so nothing can reach here unfiltered. Sanitising again on
                this side would only protect the one client that runs this file. */}
            <div
              className="blog-prose mt-8"
              dangerouslySetInnerHTML={{ __html: post.body }}
            />
          </div>
        </article>

        {related.length > 0 && (
          <section className="section-sm" style={{ background: "var(--bg-alt)" }}>
            <div className="container">
              <h2
                className="text-2xl font-bold"
                style={{ fontFamily: "var(--font-display)" }}
              >
                Baca juga
              </h2>
              <div className="mt-6 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                {related.map((item) => (
                  <BlogCard key={item.id} post={item} />
                ))}
              </div>
            </div>
          </section>
        )}
      </main>

      <Footer />
    </>
  );
}
