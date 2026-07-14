"use client";

import { useRouter } from "next/navigation";

import { updateDefaultMode } from "@/lib/api/auth";
import { cn } from "@/lib/utils";
import {
  DASHBOARD_MODES,
  MODE_HOME,
  MODE_LABEL,
  MODE_SHORT_LABEL,
  useDashboardMode,
  type DashboardMode,
} from "@/lib/hooks/use-dashboard-mode";
import { useAuthStore } from "@/stores/auth-store";

/**
 * Switches the dashboard between the organizer and participant hats. The mode
 * drives the sidebar, so this is the only way into the participant area — the
 * two sets of menus never mix.
 *
 * Picking a mode also makes it the default the next login opens in: the hat you
 * wore last is the one you want tomorrow, so there is nothing extra to set.
 *
 * Super admins have a single surface and get a plain label instead.
 */
export function ModeSwitcher() {
  const router = useRouter();
  const role = useAuthStore((s) => s.user?.role);
  const setDefaultMode = useAuthStore((s) => s.setDefaultMode);
  const mode = useDashboardMode();

  if (role === "super_admin") {
    return (
      <span className="hidden text-sm font-medium text-muted-foreground md:inline">
        Admin Platform
      </span>
    );
  }

  const select = (next: DashboardMode) => {
    if (next === mode) return;

    router.push(MODE_HOME[next]);

    // Navigating is the point; remembering it is a side effect. Update the store
    // first so nothing waits on the network, and let a failed PATCH die quietly —
    // the user still switched, they just land in the old default next time.
    setDefaultMode(next);
    void updateDefaultMode(next).catch(() => {});
  };

  return (
    <div
      role="group"
      aria-label="Mode dashboard"
      className="inline-flex items-center gap-1 rounded-lg bg-muted p-1"
    >
      {DASHBOARD_MODES.map((m) => {
        const active = m === mode;

        return (
          <button
            key={m}
            type="button"
            onClick={() => select(m)}
            aria-pressed={active}
            aria-label={MODE_LABEL[m]}
            title={MODE_LABEL[m]}
            className={cn(
              "rounded-md px-3 py-1.5 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background",
              active
                ? "bg-background text-foreground shadow-sm"
                : "text-muted-foreground hover:text-foreground"
            )}
          >
            {MODE_SHORT_LABEL[m]}
          </button>
        );
      })}
    </div>
  );
}
