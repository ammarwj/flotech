"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import {
  LayoutDashboard,
  Trophy,
  Users,
  CalendarDays,
  ListOrdered,
  Ticket,
  Award,
  Settings,
  type LucideIcon,
} from "lucide-react";

import { cn } from "@/lib/utils";

export type NavItem = {
  href: string;
  label: string;
  icon: LucideIcon;
  /** show in the mobile bottom tab bar */
  mobile?: boolean;
};

export const NAV: NavItem[] = [
  { href: "/dashboard", label: "Ringkasan", icon: LayoutDashboard, mobile: true },
  { href: "/dashboard/events", label: "Event", icon: Trophy, mobile: true },
  { href: "/dashboard/my-teams", label: "Tim Saya", icon: Users, mobile: true },
  { href: "/dashboard/schedule", label: "Jadwal", icon: CalendarDays, mobile: true },
  { href: "/dashboard/standings", label: "Klasemen", icon: ListOrdered },
  { href: "/dashboard/tickets", label: "Tiket", icon: Ticket },
  { href: "/dashboard/certificates", label: "Sertifikat", icon: Award },
  { href: "/dashboard/settings", label: "Pengaturan", icon: Settings, mobile: true },
];

function isActive(pathname: string, href: string) {
  if (href === "/dashboard") return pathname === "/dashboard";
  return pathname === href || pathname.startsWith(href + "/");
}

export function SidebarNav() {
  const pathname = usePathname();
  return (
    <nav className="flex flex-col gap-1">
      {NAV.map(({ href, label, icon: Icon }) => {
        const active = isActive(pathname, href);
        return (
          <Link
            key={href}
            href={href}
            aria-current={active ? "page" : undefined}
            className={cn(
              "group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors",
              active
                ? "bg-[var(--tint)] text-[var(--brand-600)]"
                : "text-[var(--text-2)] hover:bg-accent hover:text-foreground"
            )}
          >
            <Icon
              className={cn(
                "h-[18px] w-[18px] shrink-0 transition-colors",
                active ? "text-[var(--brand-600)]" : "text-muted-foreground group-hover:text-foreground"
              )}
            />
            {label}
          </Link>
        );
      })}
    </nav>
  );
}

export function MobileTabBar() {
  const pathname = usePathname();
  const items = NAV.filter((i) => i.mobile).slice(0, 5);
  return (
    <nav className="fixed inset-x-0 bottom-0 z-40 grid grid-cols-5 border-t border-border bg-[color-mix(in_srgb,var(--surface)_92%,transparent)] backdrop-blur-md md:hidden">
      {items.map(({ href, label, icon: Icon }) => {
        const active = isActive(pathname, href);
        return (
          <Link
            key={href}
            href={href}
            aria-current={active ? "page" : undefined}
            className={cn(
              "flex flex-col items-center gap-1 py-2.5 text-[11px] font-medium transition-colors",
              active ? "text-[var(--brand-600)]" : "text-muted-foreground"
            )}
          >
            <Icon className="h-5 w-5" />
            {label}
          </Link>
        );
      })}
    </nav>
  );
}
