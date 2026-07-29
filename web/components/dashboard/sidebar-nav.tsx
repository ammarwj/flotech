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
  BadgeCheck,
  ReceiptText,
  MessageSquareQuote,
  HelpCircle,
  Share2,
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
  /**
   * Show in the mobile bottom tab bar. At most `TAB_BAR_SLOTS` items are taken,
   * so flagging more than that silently hides the overflow — see MobileTabBar.
   */
  mobile?: boolean;
  /**
   * Label for the bottom tab bar, where a column is ~78px wide at 390px. Set it
   * whenever `label` is longer than one short word, or the tab wraps to two
   * lines while its neighbours stay on one and the row goes ragged.
   */
  short?: string;
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
  { href: "/event", label: "Jelajahi Event", icon: Compass, mobile: true, short: "Jelajahi" },
];

/**
 * SaaS super-admin navigation.
 *
 * The longest menu of the three — fifteen entries against the organizer's
 * seven — so the bottom tab bar only ever carries the five most-used ones and
 * MobileMenu is what makes the other ten reachable on a phone.
 */
export const ADMIN_NAV: NavItem[] = [
  { href: "/admin", label: "Ringkasan", icon: LayoutDashboard, mobile: true },
  { href: "/admin/users", label: "Manajemen User", icon: Users, mobile: true, short: "User" },
  { href: "/admin/plans", label: "Paket & Fitur", icon: CreditCard, mobile: true, short: "Paket" },
  {
    href: "/admin/withdrawals",
    label: "Penarikan Dana",
    icon: Banknote,
    mobile: true,
    short: "Penarikan",
  },
  { href: "/admin/payments", label: "Pembayaran & Refund", icon: ReceiptText },
  { href: "/admin/subscriptions", label: "Verifikasi Langganan", icon: BadgeCheck },
  { href: "/admin/feature-definitions", label: "Definisi Fitur", icon: SlidersHorizontal },
  { href: "/admin/testimonials", label: "Testimoni", icon: MessageSquareQuote },
  { href: "/admin/faqs", label: "FAQ", icon: HelpCircle },
  { href: "/admin/site-settings", label: "Kontak & Sosmed", icon: Share2 },
  { href: "/admin/sports", label: "Cabang Olahraga", icon: Trophy, mobile: true, short: "Cabang" },
  { href: "/admin/config-options", label: "Opsi Konfigurasi", icon: Settings2 },
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

/**
 * One row of the menu, shared by the desktop sidebar and the mobile sheet so the
 * two can't drift apart in look or in which routes count as active.
 */
function NavRow({
  item,
  active,
  onNavigate,
}: {
  item: NavItem;
  active: boolean;
  onNavigate?: () => void;
}) {
  const { href, label, icon: Icon } = item;
  return (
    <Link
      href={href}
      onClick={onNavigate}
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
}

export function SidebarNav() {
  const pathname = usePathname();
  const nav = useNav();
  return (
    <nav className="flex flex-col gap-1">
      {nav.map((item) => (
        <NavRow key={item.href} item={item} active={isActive(pathname, item.href)} />
      ))}
    </nav>
  );
}

/**
 * The *whole* menu, for the mobile sheet. MobileTabBar only has room for five
 * shortcuts, and the admin menu has fourteen entries — without this the other
 * nine have no route on a phone at all.
 *
 * `onNavigate` dismisses the sheet: the target may be the current route (a tap
 * that changes nothing), and a panel that stays open over the page it just
 * "navigated" to reads as broken.
 */
export function MobileNavList({ onNavigate }: { onNavigate?: () => void }) {
  const pathname = usePathname();
  const nav = useNav();
  return (
    <nav className="grid gap-1">
      {nav.map((item) => (
        <NavRow
          key={item.href}
          item={item}
          active={isActive(pathname, item.href)}
          onNavigate={onNavigate}
        />
      ))}
    </nav>
  );
}

/** How many `mobile` items fit across the bottom bar. */
const TAB_BAR_SLOTS = 5;

export function MobileTabBar() {
  const pathname = usePathname();
  const nav = useNav();
  const items = nav.filter((i) => i.mobile).slice(0, TAB_BAR_SLOTS);
  return (
    <nav
      className="fixed inset-x-0 bottom-0 z-40 grid border-t border-border bg-[color-mix(in_srgb,var(--surface)_92%,transparent)] backdrop-blur-md md:hidden"
      style={{ gridTemplateColumns: `repeat(${items.length}, minmax(0, 1fr))` }}
    >
      {items.map(({ href, label, short, icon: Icon }) => {
        const active = isActive(pathname, href);
        return (
          <Link
            key={href}
            href={href}
            aria-current={active ? "page" : undefined}
            // `short` is presentation only — the full label stays the accessible
            // name, so "User" still announces as "Manajemen User".
            aria-label={short ? label : undefined}
            // min-w-0 + truncate rather than letting the label wrap: five columns
            // are ~78px at 390px, so a two-word label wraps while its neighbours
            // stay on one line and the row ends up with uneven rows of text.
            className={cn(
              "flex min-w-0 flex-col items-center gap-1 px-1 py-2.5 text-[11px] font-medium transition-colors",
              active ? "text-[var(--brand-600)]" : "text-muted-foreground"
            )}
          >
            <Icon className="h-5 w-5 shrink-0" />
            <span className="w-full truncate text-center">{short ?? label}</span>
          </Link>
        );
      })}
    </nav>
  );
}
