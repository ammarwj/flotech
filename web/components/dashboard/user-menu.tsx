"use client";

import { LogOut } from "lucide-react";

import { useAuthStore } from "@/stores/auth-store";
import { useLogout } from "@/lib/hooks/use-logout";
import { Button } from "@/components/ui/button";

/**
 * Shows the signed-in user and a logout action in the dashboard header.
 *
 * Only rendered from `md` up — below that the header has no room and these
 * controls live in `MobileMenu` instead, which shares `useLogout` with this.
 */
export function UserMenu() {
  const user = useAuthStore((s) => s.user);
  const { logout, pending } = useLogout();

  return (
    <div className="flex min-w-0 items-center gap-3">
      {user && (
        // min-w-0 + truncate: a long email is otherwise unbreakable and pushes
        // the whole header wider than the viewport.
        <div className="hidden min-w-0 text-right leading-tight lg:block">
          <div className="truncate text-sm font-semibold">{user.full_name}</div>
          <div className="truncate text-xs text-muted-foreground">{user.email}</div>
        </div>
      )}
      <Button variant="outline" size="sm" onClick={logout} disabled={pending}>
        <LogOut className="h-4 w-4" />
        {pending ? "Keluar…" : "Keluar"}
      </Button>
    </div>
  );
}
