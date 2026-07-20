"use client";

import { useEffect } from "react";

import { recordEventView } from "@/lib/api/views";

/**
 * How long the same page stays "already reported" within one page load.
 * Long enough to absorb React StrictMode's double effect and an accidental
 * double navigation, short enough that a genuine revisit still counts.
 */
const QUIET_MS = 15_000;

/**
 * Module-level, deliberately NOT sessionStorage.
 *
 * A refresh re-executes this module and clears the map, so reloading the page
 * counts as another visit — which is what "kunjungan" means on the dashboard.
 * An earlier version persisted the guard in sessionStorage, which made a tab
 * count exactly once for its entire lifetime and, worse, silenced the tab
 * permanently whenever the very first request happened to fail.
 */
const lastSent = new Map<string, number>();

/** Reports one visit to a public event page. Renders nothing. */
export function ViewBeacon({ orgSlug, eventSlug }: { orgSlug: string; eventSlug: string }) {
  useEffect(() => {
    const key = `${orgSlug}/${eventSlug}`;
    const now = Date.now();
    const previous = lastSent.get(key);

    if (previous !== undefined && now - previous < QUIET_MS) return;
    lastSent.set(key, now);

    // Fire and forget. A visitor closing the tab mid-flight is normal, and an
    // uncaught rejection here would reach Sentry as noise. On failure the
    // stamp is dropped so the next mount can try again.
    recordEventView(orgSlug, eventSlug).catch(() => {
      lastSent.delete(key);
    });
  }, [orgSlug, eventSlug]);

  return null;
}
