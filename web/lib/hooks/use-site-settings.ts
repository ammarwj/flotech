"use client";

import { useQuery } from "@tanstack/react-query";

import { getPublicSiteSettings } from "@/lib/api/landing";

/** Shared so the root layout can seed this exact entry — see Providers. */
export const SITE_SETTINGS_KEY = ["public-site-settings"] as const;

/**
 * Public site settings — contact details, socials, and the platform branding.
 *
 * One hook rather than a `useQuery` per caller, because react-query resolves
 * options per *observer*, not per key: the footer asking for the default 60s
 * while the logo asks for 5 minutes means whichever mounts refetches on its own
 * schedule and the other sees the result anyway. Sharing the hook is what makes
 * the cache window a fact rather than a hope.
 *
 * Long-lived on purpose. Branding and contact details change about never, and
 * this renders in every layout — the admin page invalidates this exact key on
 * save, so an edit still lands immediately for the person who made it.
 */
export function useSiteSettings() {
  return useQuery({
    queryKey: SITE_SETTINGS_KEY,
    queryFn: getPublicSiteSettings,
    staleTime: 5 * 60 * 1000,
    gcTime: 30 * 60 * 1000,
  });
}
