import type { SocialLinks, SocialPlatform } from "@/types/api";

export interface SocialPlatformMeta {
  key: SocialPlatform;
  label: string;
  /** Example input; the API accepts a bare handle or a full URL. */
  placeholder: string;
  /**
   * The platform's own mark colour, used on hover so a row of muted glyphs
   * identifies itself the moment you reach for one. X's mark is monochrome by
   * design, so it follows the theme's text colour instead of a fixed hex.
   */
  color: string;
}

/**
 * The platforms the settings form offers, in display order. Mirrors
 * `Organization::SOCIAL_PLATFORMS` on the API, which normalizes a bare handle
 * ("@klubku") into the full profile URL — so what we store and show is a link.
 */
export const SOCIAL_PLATFORMS: SocialPlatformMeta[] = [
  {
    key: "instagram",
    label: "Instagram",
    placeholder: "@klubku atau instagram.com/klubku",
    color: "#E4405F",
  },
  {
    key: "youtube",
    label: "YouTube",
    placeholder: "@klubku atau youtube.com/@klubku",
    color: "#FF0033",
  },
  { key: "x", label: "X", placeholder: "@klubku atau x.com/klubku", color: "var(--text)" },
  {
    key: "tiktok",
    label: "TikTok",
    placeholder: "@klubku atau tiktok.com/@klubku",
    color: "#FE2C55",
  },
  {
    key: "facebook",
    label: "Facebook",
    placeholder: "klubku atau facebook.com/klubku",
    color: "#1877F2",
  },
];

/** Platforms the organizer actually filled in, ready to render as links. */
export function filledSocialLinks(links: SocialLinks | null | undefined) {
  return SOCIAL_PLATFORMS.flatMap((platform) => {
    const url = links?.[platform.key];
    return url ? [{ ...platform, url }] : [];
  });
}
