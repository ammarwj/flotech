"use client";

import { usePathname, useRouter } from "next/navigation";

import { updateDefaultMode } from "@/lib/api/auth";
import { cn } from "@/lib/utils";
import {
  DASHBOARD_MODES,
  isCrewOnly,
  MODE_HOME,
  MODE_LABEL,
  MODE_SHORT_LABEL,
  modeFromPath,
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
 * Two kinds of account have a single surface and get a plain label instead:
 * super admins, and referees or match staff whose only reason to be here is the
 * event they were assigned to. For the second one the buttons would be worse
 * than useless — both would lead somewhere the API answers 403.
 */
export function ModeSwitcher({
  onSelect,
  fullWidth = false,
}: {
  onSelect?: () => void;
  /** Stretch to fill its container, splitting the width evenly between the two
   *  modes. Used in the mobile sheet, where an inline group left dead space on
   *  the right; the header keeps the compact inline sizing. */
  fullWidth?: boolean;
} = {}) {
  const router = useRouter();
  const pathname = usePathname();
  const role = useAuthStore((s) => s.user?.role);
  const user = useAuthStore((s) => s.user);
  const setDefaultMode = useAuthStore((s) => s.setDefaultMode);
  const mode = useDashboardMode();
  // /account belongs to no mode — it applies to all of them — so `mode` there is
  // the hat being worn, not the surface being looked at. Without this the active
  // button is a dead end on that page: it matches `mode`, so the early return
  // below fires and the one hat the user is actually in has no way home.
  const insideMode = modeFromPath(pathname) !== null;

  if (role === "super_admin") {
    return (
      <span className="hidden text-sm font-medium text-muted-foreground md:inline">
        Admin Platform
      </span>
    );
  }

  // Crew and nothing else — the test itself lives in `isCrewOnly`, which is
  // also what decides where "Login sebagai" lands such an account. Two copies
  // would eventually disagree, and the shape that disagreement takes is an
  // account routed to /officiating that is then handed the organizer switcher.
  if (user && isCrewOnly(user)) {
    return (
      <span className="hidden text-sm font-medium text-muted-foreground md:inline">
        {MODE_LABEL.officiating}
      </span>
    );
  }

  const select = (next: DashboardMode) => {
    // Still dismiss the mobile panel when the active mode is tapped: to the user
    // that reads as "yes, this one", not as a no-op.
    onSelect?.();
    if (next === mode && insideMode) return;

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
      className={cn(
        "items-center gap-1 rounded-lg bg-muted p-1",
        fullWidth ? "flex w-full" : "inline-flex"
      )}
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
              fullWidth && "flex-1",
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
