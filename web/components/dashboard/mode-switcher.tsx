"use client";

import { useRouter } from "next/navigation";

import { Select } from "@/components/ui/select";
import {
  MODE_HOME,
  MODE_LABEL,
  useDashboardMode,
  type DashboardMode,
} from "@/lib/hooks/use-dashboard-mode";
import { useAuthStore } from "@/stores/auth-store";

/**
 * Switches the dashboard between the organizer and participant hats. The mode
 * drives the sidebar, so this is the only way into the participant area — the
 * two sets of menus never mix.
 *
 * Super admins have a single surface and get a plain label instead.
 */
export function ModeSwitcher() {
  const router = useRouter();
  const role = useAuthStore((s) => s.user?.role);
  const mode = useDashboardMode();

  if (role === "super_admin") {
    return (
      <span className="hidden text-sm font-medium text-muted-foreground md:inline">
        Admin Platform
      </span>
    );
  }

  return (
    <Select
      aria-label="Ganti mode dashboard"
      value={mode}
      onChange={(e) => router.push(MODE_HOME[e.target.value as DashboardMode])}
      className="h-9 w-auto min-w-[170px] font-medium"
    >
      <option value="organizer">{MODE_LABEL.organizer}</option>
      <option value="participant">{MODE_LABEL.participant}</option>
    </Select>
  );
}
