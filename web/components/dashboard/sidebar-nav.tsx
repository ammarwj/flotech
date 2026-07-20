"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import {
  LayoutDashboard,
  Trophy,
  Users,
  Compass,
  Ticket,
  Award,
  Settings,
  Settings2,
  SlidersHorizontal,
  CreditCard,
  Wallet,
  Banknote,
  ReceiptText,
  MessageSquareQuote,
  HelpCircle,
  Activity,
  BarChart3,
  type LucideIcon,
} from "lucide-react";

import { cn } from "@/lib/utils";
import { useDashboardMode } from "@/lib/hooks/use-dashboard-mode";
import { useAuthStore } from "@/stores/auth-store";

export type NavItem = {
  href: string;
  label: string;
  icon: LucideIcon;
  /** show in the mobile bottom tab bar */
  mobile?: boolean;
};

/**
 * Organizer navigation.
 *
 * Jadwal & klasemen are deliberately absent: they only exist per event, and live
 * at /organizer/events/[id]/schedule. A global menu entry for them would have to
 * invent a cross-event view that has no meaning.
 */
export const ORGANIZER_NAV: NavItem[] = [
  { href: "/organizer", label: "Ringkasan", icon: LayoutDashboard, mobile: true },
  { href: "/organizer/events", label: "Event", icon: Trophy, mobile: true },
  { href: "/organizer/tickets", label: "Tiket", icon: Ticket, mobile: true },
  { href: "/organizer/wallet", label: "Dompet", icon: Wallet, mobile: true },
  { href: "/organizer/certificates", label: "Sertifikat", icon: Award },
  { href: "/organizer/subscription", label: "Langganan", icon: CreditCard },
  { href: "/organizer/settings", label: "Pengaturan", icon: Settings, mobile: true },
];

/**
 * Participant navigation. The same account can wear both hats, so this is a
 * separate mode reachable from the header switcher rather than an item mixed
 * into the organizer menu.
 */
export const PARTICIPANT_NAV: NavItem[] = [
  { href: "/participant", label: "Tim Saya", icon: Users, mobile: true },
  { href: "/event", label: "Jelajahi Event", icon: Compass, mobile: true },
];

/** SaaS super-admin navigation. */
export const ADMIN_NAV: NavItem[] = [
  { href: "/admin", label: "Ringkasan", icon: LayoutDashboard, mobile: true },
  { href: "/admin/users", label: "Manajemen User", icon: Users, mobile: true },
  { href: "/admin/plans", label: "Paket & Fitur", icon: CreditCard, mobile: true },
  { href: "/admin/withdrawals", label: "Penarikan Dana", icon: Banknote, mobile: true },
  { href: "/admin/payments", label: "Pembayaran & Refund", icon: ReceiptText },
  { href: "/admin/feature-definitions", label: "Definisi Fitur", icon: SlidersHorizontal },
  { href: "/admin/testimonials", label: "Testimoni", icon: MessageSquareQuote },
  { href: "/admin/faqs", label: "FAQ", icon: HelpCircle },
  { href: "/admin/sports", label: "Cabang Olahraga", icon: Trophy, mobile: true },
  { href: "/admin/config-options", label: "Opsi Konfigurasi", icon: Settings2, mobile: true },
  { href: "/admin/settings", label: "Pengaturan Platform", icon: Settings },
  { href: "/admin/active-sessions", label: "Sesi Aktif", icon: Activity },
  { href: "/admin/visitors", label: "Statistik Pengunjung", icon: BarChart3 },
];

/** Pick the navigation set for the signed-in user's role and current mode. */
function useNav(): NavItem[] {
  const role = useAuthStore((s) => s.user?.role);
  const mode = useDashboardMode();

  if (role === "super_admin") return ADMIN_NAV;
  return mode === "participant" ? PARTICIPANT_NAV : ORGANIZER_NAV;
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
