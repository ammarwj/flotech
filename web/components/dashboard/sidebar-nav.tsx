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
  CreditCard,
  type LucideIcon,
} from "lucide-react";

import { cn } from "@/lib/utils";
import { useAuthStore } from "@/stores/auth-store";

export type NavItem = {
  href: string;
  label: string;
  icon: LucideIcon;
  /** show in the mobile bottom tab bar */
  mobile?: boolean;
};

/** Organizer (regular user) navigation. */
export const ORGANIZER_NAV: NavItem[] = [
  { href: "/organizer", label: "Ringkasan", icon: LayoutDashboard, mobile: true },
  { href: "/organizer/events", label: "Event", icon: Trophy, mobile: true },
  { href: "/participant", label: "Tim Saya", icon: Users, mobile: true },
  { href: "/organizer/schedule", label: "Jadwal", icon: CalendarDays, mobile: true },
  { href: "/organizer/standings", label: "Klasemen", icon: ListOrdered },
  { href: "/organizer/tickets", label: "Tiket", icon: Ticket },
  { href: "/organizer/certificates", label: "Sertifikat", icon: Award },
  { href: "/organizer/settings", label: "Pengaturan", icon: Settings, mobile: true },
];

/** SaaS super-admin navigation. */
export const ADMIN_NAV: NavItem[] = [
  { href: "/admin", label: "Ringkasan", icon: LayoutDashboard, mobile: true },
  { href: "/admin/plans", label: "Paket & Fitur", icon: CreditCard, mobile: true },
];

/** Pick the navigation set for the signed-in user's role. */
function useNav(): NavItem[] {
  const role = useAuthStore((s) => s.user?.role);
  return role === "super_admin" ? ADMIN_NAV : ORGANIZER_NAV;
}

function isActive(pathname: string, href: string) {
  if (href === "/organizer" || href === "/admin") return pathname === href;
  return pathname === href || pathname.startsWith(href + "/");
}

export function SidebarNav() {
  const pathname = usePathname();
  const nav = useNav();
  return (
    <nav className="flex flex-col gap-1">
      {nav.map(({ href, label, icon: Icon }) => {
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
  const nav = useNav();
  const items = nav.filter((i) => i.mobile).slice(0, 5);
  return (
    <nav
      className="fixed inset-x-0 bottom-0 z-40 grid border-t border-border bg-[color-mix(in_srgb,var(--surface)_92%,transparent)] backdrop-blur-md md:hidden"
      style={{ gridTemplateColumns: `repeat(${items.length}, minmax(0, 1fr))` }}
    >
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
