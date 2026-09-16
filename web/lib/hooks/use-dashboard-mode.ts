"use client";

import { usePathname } from "next/navigation";

/**
 * The dashboard has two hats a regular user can wear: organizer (their own
 * events) and participant (teams they registered elsewhere). The *current* mode
 * is derived from the URL rather than stored, so a deep link or refresh lands in
 * the right one and there is no state to keep in sync with the router.
 *
 * Which mode a fresh login opens in is a separate thing: it comes from the
 * server (`user.default_mode`), written every time the switcher is used.
 *
 * `officiating` is a third mode but not a third hat: nobody chooses it, an
 * organizer puts them there. It has no button in the switcher (see
 * DASHBOARD_MODES) — the only way in is a URL under /officiating, which the
 * server refuses to anyone with no personnel row.
 */
export type DashboardMode = "organizer" | "participant" | "officiating";

export const MODE_HOME: Record<DashboardMode, string> = {
  organizer: "/organizer",
  participant: "/participant",
  officiating: "/officiating",
};

export const MODE_LABEL: Record<DashboardMode, string> = {
  organizer: "Dashboard Organizer",
  participant: "Area Peserta",
  officiating: "Area Petugas",
};

/** What fits on a segmented button; the long label stays as the accessible name. */
export const MODE_SHORT_LABEL: Record<DashboardMode, string> = {
  organizer: "Organizer",
  participant: "Peserta",
  officiating: "Petugas",
};

/**
 * A mode a user can *choose* — in the switcher, or on the register form.
 *
 * Narrower than `DashboardMode` on purpose, and it is the type that keeps the
 * two apart: anything keyed by the choosable set (register's MODE_PITCH) would
 * otherwise have to invent copy for `officiating`, and copy that exists reads as
 * an offer. Widening this is how a future fourth mode becomes a compile error in
 * every screen that has to have something to say about it.
 */
export type ChoosableMode = Extract<DashboardMode, "organizer" | "participant">;

/**
 * What the switcher renders — deliberately still two.
 *
 * A third button would offer every account a mode almost none of them can
 * enter. Crew reach their area by landing there, and ModeSwitcher gives them a
 * plain label instead, the same way it does for super admins.
 */
export const DASHBOARD_MODES: ChoosableMode[] = ["organizer", "participant"];

export function useDashboardMode(): DashboardMode {
  const pathname = usePathname();
  // Checked before participant: a referee is often also somebody's manager, and
  // the URL is what says which surface they are looking at right now.
  if (pathname.startsWith("/officiating")) return "officiating";
  return pathname.startsWith("/participant") ? "participant" : "organizer";
}
