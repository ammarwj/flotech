import type { BlogPostSummary } from "@/types/api";

/**
 * Everything the blog UI needs to say about an article, in one place.
 *
 * The admin list and the public page both label dates and both ask "is this
 * live?", and a second copy of either would drift — the same reason
 * `knockoutReadiness()` and `swissReadiness()` exist instead of each view
 * deriving its own answer.
 */

export type BlogStatus = "draft" | "scheduled" | "published";

/**
 * Three states, not two. A draft and a scheduled article are both invisible,
 * but only one of them is waiting for the author — collapsing them into
 * "unpublished" hides the difference the admin list exists to show.
 *
 * Mirrors BlogPost::scopePublished() on the API: null, or in the future, is
 * not public.
 */
export function blogStatus(post: Pick<BlogPostSummary, "published_at">): BlogStatus {
  if (!post.published_at) return "draft";
  return new Date(post.published_at) > new Date() ? "scheduled" : "published";
}

export const BLOG_STATUS_LABELS: Record<BlogStatus, string> = {
  draft: "Draf",
  scheduled: "Terjadwal",
  published: "Terbit",
};

/** "13 Oktober 2026". Null dates belong to drafts, which say so instead. */
export function blogDate(iso: string | null): string {
  if (!iso) return "Belum terbit";
  return new Date(iso).toLocaleDateString("id-ID", {
    day: "numeric",
    month: "long",
    year: "numeric",
  });
}

/** "13 Okt 2026, 09:30" — the admin list needs the time a schedule fires. */
export function blogDateTime(iso: string | null): string {
  if (!iso) return "—";
  return new Date(iso).toLocaleString("id-ID", {
    day: "numeric",
    month: "short",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}

export function readTimeLabel(minutes: number): string {
  return `${Math.max(1, minutes)} menit baca`;
}

/**
 * ISO string ⇄ the value a `datetime-local` input wants, in the viewer's own
 * zone. Going through `toISOString()` in either direction would shift the
 * displayed time by the UTC offset — an admin in WIB scheduling 09:00 would
 * see 02:00 the next time they opened the article.
 */
export function toDateTimeLocal(iso: string | null): string {
  if (!iso) return "";
  const d = new Date(iso);
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

export function fromDateTimeLocal(value: string): string | null {
  if (!value) return null;
  const d = new Date(value);
  return Number.isNaN(d.getTime()) ? null : d.toISOString();
}
