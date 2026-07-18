"use client";

import { useState } from "react";
import { LogOut } from "lucide-react";

import { Button } from "@/components/ui/button";
import { ModeSwitcher } from "@/components/dashboard/mode-switcher";
import { MobileMenuButton, MobileSheet, useSheetClose } from "@/components/shared/mobile-sheet";
import { ThemeToggleButton } from "@/components/shared/theme-toggle-button";
import { useLogout } from "@/lib/hooks/use-logout";
import { useAuthStore } from "@/stores/auth-store";

/**
 * The dashboard's mobile chrome. Below `md` the header only has room for the
 * logo and this button, so every control that lives in the desktop header —
 * theme, mode, sign out — moves in here.
 *
 * Dropping the mode switcher on mobile instead was not an option: MobileTabBar
 * renders whichever nav `useDashboardMode()` resolves to, so it *reflects* the
 * mode without offering any way to change it. This panel is the only way to
 * switch hats on a phone.
 */
export function MobileMenu() {
  const [open, setOpen] = useState(false);

  return (
    <>
      {/* md:hidden sits on the wrapper, not the button: `.theme-toggle` sets
          `display: grid` unlayered in globals.css, and unlayered CSS beats every
          layered Tailwind utility — on the button itself it stayed visible on
          desktop. */}
      <div className="md:hidden">
        <MobileMenuButton onClick={() => setOpen(true)} expanded={open} />
      </div>
      {/* Mounted only while open, so each open starts from a clean panel. */}
      {open ? <Panel onClose={() => setOpen(false)} /> : null}
    </>
  );
}

function Panel({ onClose }: { onClose: () => void }) {
  const user = useAuthStore((s) => s.user);
  const role = useAuthStore((s) => s.user?.role);
  const { logout, pending } = useLogout();

  return (
    <MobileSheet
      label="Menu"
      closeAbove={768}
      onClose={onClose}
      header={
        <>
          <div className="truncate text-sm font-semibold">{user?.full_name}</div>
          <div className="truncate text-xs text-muted-foreground">{user?.email}</div>
        </>
      }
      footer={
        <Button variant="outline" className="w-full" onClick={logout} disabled={pending}>
          <LogOut className="h-4 w-4" />
          {pending ? "Keluar…" : "Keluar"}
        </Button>
      }
    >
      <Body superAdmin={role === "super_admin"} />
    </MobileSheet>
  );
}

/** Inside the sheet, so it can dismiss it *with* the exit animation. */
function Body({ superAdmin }: { superAdmin: boolean }) {
  const close = useSheetClose();

  return (
    <>
      {/* Super admins have a single surface, so ModeSwitcher renders nothing for
          them — skip the heading too rather than label an empty row. */}
      {!superAdmin && (
        <div className="grid gap-2">
          <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
            Mode
          </span>
          <ModeSwitcher onSelect={close} fullWidth />
        </div>
      )}

      <div className="flex items-center justify-between gap-3">
        <span className="text-sm font-medium">Tema</span>
        <ThemeToggleButton />
      </div>
    </>
  );
}
