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
 */
export type DashboardMode = "organizer" | "participant";

export const MODE_HOME: Record<DashboardMode, string> = {
  organizer: "/organizer",
  participant: "/participant",
};

export const MODE_LABEL: Record<DashboardMode, string> = {
  organizer: "Dashboard Organizer",
  participant: "Area Peserta",
};

/** What fits on a segmented button; the long label stays as the accessible name. */
export const MODE_SHORT_LABEL: Record<DashboardMode, string> = {
  organizer: "Organizer",
  participant: "Peserta",
};

export const DASHBOARD_MODES: DashboardMode[] = ["organizer", "participant"];

export function useDashboardMode(): DashboardMode {
  const pathname = usePathname();
  return pathname.startsWith("/participant") ? "participant" : "organizer";
}
