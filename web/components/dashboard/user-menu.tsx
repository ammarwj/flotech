"use client";

import Link from "next/link";
import { LogOut, UserRound } from "lucide-react";

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
        <Link
          href="/account"
          className="hidden min-w-0 rounded-md text-right leading-tight transition-opacity hover:opacity-80 lg:block"
        >
          <div className="truncate text-sm font-semibold">{user.full_name}</div>
          <div className="truncate text-xs text-muted-foreground">{user.email}</div>
        </Link>
      )}
      {/* The name above is the same link, but it's hidden below lg — this keeps
          the account page reachable at every width the header renders at. */}
      <Button variant="outline" size="sm" asChild>
        <Link href="/account">
          <UserRound className="h-4 w-4" />
          <span className="sr-only lg:not-sr-only">Akun</span>
        </Link>
      </Button>
      <Button variant="outline" size="sm" onClick={logout} disabled={pending}>
        <LogOut className="h-4 w-4" />
        {pending ? "Keluar…" : "Keluar"}
      </Button>
    </div>
  );
}
