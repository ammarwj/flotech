"use client";

import Link from "next/link";
import { CalendarDays, Clock, Newspaper } from "lucide-react";

import { Badge } from "@/components/ui/badge";
import { Card } from "@/components/ui/card";
import { blogDate, readTimeLabel } from "@/lib/blog";
import type { BlogPostSummary } from "@/types/api";

/**
 * One article in a grid. Shared by the blog index and the "baca juga" strip at
 * the foot of an article, so the two never drift apart.
 */
export function BlogCard({ post, priority }: { post: BlogPostSummary; priority?: boolean }) {
  return (
    <Card className="group overflow-hidden transition-colors hover:border-[var(--border-strong)]">
      <Link href={`/blog/${post.slug}`} className="flex h-full flex-col">
        <div className="relative aspect-[16/9] overflow-hidden bg-[var(--tint)]">
          {post.cover_url ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img
              src={post.cover_url}
              alt=""
              loading={priority ? "eager" : "lazy"}
              className="h-full w-full object-cover transition-transform duration-300 group-hover:scale-[1.03]"
            />
          ) : (
            <span className="grid h-full w-full place-items-center text-[var(--brand-600)]">
              <Newspaper className="h-9 w-9" />
            </span>
          )}
          {post.category && (
            // Solid backing: the badge tint is translucent and would wash out
            // over a cover photo. Same treatment as PublicEventCard's status.
            <span className="absolute left-3 top-3 inline-flex rounded-full bg-[var(--bg)]">
              <Badge variant="info">{post.category.name}</Badge>
            </span>
          )}
        </div>

        <div className="flex flex-1 flex-col gap-2 p-5">
          <h3
            className="text-lg font-bold leading-snug transition-colors group-hover:text-[var(--brand-600)]"
            style={{ fontFamily: "var(--font-display)" }}
          >
            {post.title}
          </h3>
          <p className="line-clamp-3 text-sm leading-relaxed text-muted-foreground">
            {post.excerpt}
          </p>

          <div className="mt-auto flex flex-wrap items-center gap-x-4 gap-y-1 pt-3 text-xs text-muted-foreground">
            <span className="inline-flex items-center gap-1.5">
              <CalendarDays className="h-3.5 w-3.5" />
              {blogDate(post.published_at)}
            </span>
            <span className="inline-flex items-center gap-1.5">
              <Clock className="h-3.5 w-3.5" />
              {readTimeLabel(post.read_minutes)}
            </span>
          </div>
        </div>
      </Link>
    </Card>
  );
}
