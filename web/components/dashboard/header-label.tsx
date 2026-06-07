"use client";

import { useAuthStore } from "@/stores/auth-store";

/** Role-aware label in the dashboard header. */
export function HeaderLabel() {
  const role = useAuthStore((s) => s.user?.role);
  const label = role === "super_admin" ? "Admin Platform" : "Dashboard Organizer";

  return (
    <span className="hidden text-sm font-medium text-muted-foreground md:inline">{label}</span>
  );
}
