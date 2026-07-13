import type { SocialLinks, SocialPlatform } from "@/types/api";

export interface SocialPlatformMeta {
  key: SocialPlatform;
  label: string;
  /** Example input; the API accepts a bare handle or a full URL. */
  placeholder: string;
}

/**
 * The platforms the settings form offers, in display order. Mirrors
 * `Organization::SOCIAL_PLATFORMS` on the API, which normalizes a bare handle
 * ("@klubku") into the full profile URL — so what we store and show is a link.
 */
export const SOCIAL_PLATFORMS: SocialPlatformMeta[] = [
  { key: "instagram", label: "Instagram", placeholder: "@klubku atau instagram.com/klubku" },
  { key: "youtube", label: "YouTube", placeholder: "@klubku atau youtube.com/@klubku" },
  { key: "x", label: "X", placeholder: "@klubku atau x.com/klubku" },
  { key: "tiktok", label: "TikTok", placeholder: "@klubku atau tiktok.com/@klubku" },
  { key: "facebook", label: "Facebook", placeholder: "klubku atau facebook.com/klubku" },
];

/** Platforms the organizer actually filled in, ready to render as links. */
export function filledSocialLinks(links: SocialLinks | null | undefined) {
  return SOCIAL_PLATFORMS.flatMap((platform) => {
    const url = links?.[platform.key];
    return url ? [{ ...platform, url }] : [];
  });
}
