"use client";

import { usePathname } from "next/navigation";

/**
 * The dashboard has two hats a regular user can wear: organizer (their own
 * events) and participant (teams they registered elsewhere). The mode is derived
 * from the URL rather than stored, so a deep link or refresh lands in the right
 * one and there is no state to keep in sync with the router.
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

export function useDashboardMode(): DashboardMode {
  const pathname = usePathname();
  return pathname.startsWith("/participant") ? "participant" : "organizer";
}
