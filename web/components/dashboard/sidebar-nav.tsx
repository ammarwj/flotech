"use client";

import { useEffect } from "react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import {
  LayoutDashboard,
  Trophy,
  Users,
  Compass,
  Ticket,
  Award,
  ChevronDown,
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
import { create } from "zustand";

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
 * A run of items under one heading.
 *
 * `id` is deliberately separate from `label`: it keys the persisted collapse
 * preference (and the `aria-controls` target), so renaming a heading must not
 * silently reset which groups the user had folded.
 */
export type NavSection = {
  id: string;
  /**
   * `null` renders a plain list — no heading, no chevron, nothing to collapse.
   * Every nav has exactly one of these at the top so the overview row can't be
   * folded away from the menu.
   */
  label: string | null;
  items: NavItem[];
};

/**
 * Organizer navigation.
 *
 * One unlabelled section: seven entries don't need headings, and this keeps
 * `useNav()` returning a single shape so no renderer branches on role.
 *
 * Jadwal & klasemen are deliberately absent: they only exist per event, and live
 * at /organizer/events/[id]/schedule. A global menu entry for them would have to
 * invent a cross-event view that has no meaning.
 */
export const ORGANIZER_NAV: NavSection[] = [
  {
    id: "organizer",
    label: null,
    items: [
      { href: "/organizer", label: "Ringkasan", icon: LayoutDashboard, mobile: true },
      { href: "/organizer/events", label: "Event", icon: Trophy, mobile: true },
      { href: "/organizer/tickets", label: "Tiket", icon: Ticket, mobile: true },
      { href: "/organizer/wallet", label: "Dompet", icon: Wallet, mobile: true },
      { href: "/organizer/certificates", label: "Sertifikat", icon: Award },
      { href: "/organizer/billing", label: "Pembelian Paket", icon: CreditCard },
      { href: "/organizer/settings", label: "Pengaturan", icon: Settings, mobile: true },
    ],
  },
];

/**
 * Participant navigation. The same account can wear both hats, so this is a
 * separate mode reachable from the header switcher rather than an item mixed
 * into the organizer menu.
 */
export const PARTICIPANT_NAV: NavSection[] = [
  {
    id: "participant",
    label: null,
    items: [
      { href: "/participant", label: "Tim Saya", icon: Users, mobile: true },
      { href: "/event", label: "Jelajahi Event", icon: Compass, mobile: true, short: "Jelajahi" },
    ],
  },
];

/**
 * SaaS super-admin navigation — the only one long enough to need headings.
 *
 * Grouped by domain and ordered by how often a group is opened: the queues that
 * are worked daily sit above the catalogues and settings that are touched once
 * a quarter. `Ringkasan` stays outside every group.
 *
 * The bottom tab bar only ever carries the `mobile` items that fit
 * `TAB_BAR_SLOTS`; MobileMenu is what makes the rest reachable on a phone.
 */
export const ADMIN_NAV: NavSection[] = [
  {
    id: "admin-overview",
    label: null,
    items: [{ href: "/admin", label: "Ringkasan", icon: LayoutDashboard, mobile: true }],
  },
  {
    id: "admin-users",
    label: "Pengguna",
    items: [
      { href: "/admin/users", label: "Manajemen User", icon: Users, mobile: true, short: "User" },
      { href: "/admin/active-sessions", label: "Sesi Aktif", icon: Activity },
    ],
  },
  {
    id: "admin-finance",
    label: "Keuangan",
    items: [
      {
        href: "/admin/withdrawals",
        label: "Penarikan Dana",
        icon: Banknote,
        mobile: true,
        short: "Penarikan",
      },
      { href: "/admin/payments", label: "Pembayaran & Refund", icon: ReceiptText },
    ],
  },
  {
    id: "admin-plans",
    label: "Paket & Pembelian",
    items: [
      {
        href: "/admin/plans",
        label: "Paket & Fitur",
        icon: CreditCard,
        mobile: true,
        short: "Paket",
      },
      { href: "/admin/feature-definitions", label: "Definisi Fitur", icon: SlidersHorizontal },
      { href: "/admin/plan-orders", label: "Verifikasi Pembelian", icon: BadgeCheck },
    ],
  },
  {
    id: "admin-catalog",
    label: "Katalog Olahraga",
    items: [
      {
        href: "/admin/sports",
        label: "Cabang Olahraga",
        icon: Trophy,
        mobile: true,
        short: "Cabang",
      },
      { href: "/admin/config-options", label: "Opsi Konfigurasi", icon: Settings2 },
    ],
  },
  {
    id: "admin-landing",
    label: "Konten Landing",
    items: [
      { href: "/admin/testimonials", label: "Testimoni", icon: MessageSquareQuote },
      { href: "/admin/faqs", label: "FAQ", icon: HelpCircle },
      { href: "/admin/site-settings", label: "Kontak & Sosmed", icon: Share2 },
    ],
  },
  {
    id: "admin-system",
    label: "Sistem",
    items: [
      { href: "/admin/settings", label: "Pengaturan Platform", icon: Settings },
      { href: "/admin/visitors", label: "Statistik Pengunjung", icon: BarChart3 },
    ],
  },
];

/** Every item of every section, in menu order. */
export function navItems(sections: NavSection[]): NavItem[] {
  return sections.flatMap((section) => section.items);
}

/** Pick the navigation set for the signed-in user's role and current mode. */
function useNav(): NavSection[] {
  const role = useAuthStore((s) => s.user?.role);
  const mode = useDashboardMode();

  if (role === "super_admin") return ADMIN_NAV;
  return mode === "participant" ? PARTICIPANT_NAV : ORGANIZER_NAV;
}

function isActive(pathname: string, href: string) {
  if (href === "/organizer" || href === "/admin") return pathname === href;
  return pathname === href || pathname.startsWith(href + "/");
}

const GROUPS_KEY = "flo:nav-groups";

function readStoredGroups(): Record<string, boolean> {
  if (typeof window === "undefined") return {};
  try {
    const raw = window.localStorage.getItem(GROUPS_KEY);
    const parsed = raw ? JSON.parse(raw) : null;
    // Anything but a plain object (an older shape, hand-edited storage) is
    // dropped rather than merged — a bad value here would fold the whole menu.
    return parsed && typeof parsed === "object" && !Array.isArray(parsed) ? parsed : {};
  } catch {
    return {};
  }
}

function writeStoredGroups(prefs: Record<string, boolean>): void {
  if (typeof window === "undefined") return;
  try {
    window.localStorage.setItem(GROUPS_KEY, JSON.stringify(prefs));
  } catch {
    // Private mode / quota. The menu still works, it just forgets.
  }
}

/**
 * Which groups the user has explicitly folded or unfolded. Absent id = follow
 * the default (open only while it holds the active route).
 *
 * Module-level rather than `useState` in the group: the desktop `SidebarNav` is
 * `hidden md:flex`, so it stays *mounted* on a phone alongside MobileNavList in
 * the sheet. Two local states would let the hidden one keep stale prefs and
 * overwrite the sheet's writes to localStorage on the next toggle.
 *
 * Initial state is empty and `hydrate()` fills it from an effect: reading
 * storage in the initializer would render differently on the server, and the
 * default already comes out right without it.
 */
const useNavGroups = create<{
  prefs: Record<string, boolean>;
  hydrated: boolean;
  hydrate: () => void;
  /**
   * Records an explicit choice. The caller passes the state it wants because
   * "absent" is not the same as "closed" — a group with no pref is open while it
   * holds the active route, and deriving the next value from `prefs[id]` alone
   * made that group's own heading a no-op on first click.
   */
  set: (id: string, open: boolean) => void;
  /** Forget a choice, handing the group back to the active-route default. */
  reset: (id: string) => void;
}>((set, get) => ({
  prefs: {},
  hydrated: false,
  hydrate: () => {
    if (get().hydrated) return;
    // Merged *under* whatever is already in state so a toggle that raced this
    // (a click in the same tick as mount) isn't undone by stale storage.
    set((s) => ({ prefs: { ...readStoredGroups(), ...s.prefs }, hydrated: true }));
  },
  set: (id, open) =>
    set((s) => {
      const prefs = { ...s.prefs, [id]: open };
      writeStoredGroups(prefs);
      return { prefs };
    }),
  reset: (id) =>
    set((s) => {
      if (!(id in s.prefs)) return s;
      const prefs = { ...s.prefs };
      delete prefs[id];
      writeStoredGroups(prefs);
      return { prefs };
    }),
}));

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
        // `relative` anchors the spine marker that `.nav-group-rows` draws for
        // the active row — see globals.css.
        "group relative flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors",
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

/**
 * A collapsible heading plus its rows.
 *
 * The rows are never unmounted: `aria-controls` needs a real element to point
 * at, and the fold animates (see `.nav-group-rows`). `inert` is what keeps a
 * folded group's links out of the tab order — the job `hidden` would have done.
 */
function NavGroup({
  section,
  pathname,
  onNavigate,
}: {
  section: NavSection;
  pathname: string;
  onNavigate?: () => void;
}) {
  const { id, label, items } = section;
  const pref = useNavGroups((s) => s.prefs[id]);
  const hydrated = useNavGroups((s) => s.hydrated);
  const setOpen = useNavGroups((s) => s.set);
  const reset = useNavGroups((s) => s.reset);

  const holdsActive = items.some((item) => isActive(pathname, item.href));
  const open = pref ?? holdsActive;

  // Entering a group hands it back to the default, i.e. opens it — otherwise the
  // row you just navigated to would sit behind a folded heading. Keyed on the
  // group holding the active route rather than on `pathname`, so folding the
  // group you're already in survives navigating *within* it.
  //
  // Gated on `hydrated` because child effects run before the parent's: without
  // it this fires while `prefs` is still empty, and the folded state loaded a
  // moment later would keep the active row hidden with nothing left to reopen it.
  useEffect(() => {
    if (hydrated && holdsActive) reset(id);
  }, [hydrated, holdsActive, id, reset]);

  return (
    <div className="mt-4 first:mt-0">
      <button
        type="button"
        onClick={() => setOpen(id, !open)}
        aria-expanded={open}
        aria-controls={`nav-group-${id}`}
        // Colours for both states live in `.nav-group-label`, next to the spine
        // they have to agree with — and the active blue differs per theme.
        data-holds-active={!open && holdsActive}
        className="nav-group-label flex w-full items-center gap-2 rounded-lg px-3 py-1.5 transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--brand-500)]"
      >
        <span className="truncate">{label}</span>
        {/* Trailing, so the headings and the spine share one clean left edge. */}
        <ChevronDown
          className={cn(
            "ml-auto h-3.5 w-3.5 shrink-0 transition-transform duration-200",
            !open && "-rotate-90"
          )}
        />
      </button>
      <div
        id={`nav-group-${id}`}
        data-open={open}
        aria-hidden={!open}
        inert={!open}
        className="nav-group-rows mt-1"
      >
        <div className="flex flex-col gap-1">
          {items.map((item) => (
            <NavRow
              key={item.href}
              item={item}
              active={isActive(pathname, item.href)}
              onNavigate={onNavigate}
            />
          ))}
        </div>
      </div>
    </div>
  );
}

/**
 * The sections of whichever nav is current, shared by the desktop sidebar and
 * the mobile sheet so a group folded in one is folded in the other.
 */
function NavSections({ onNavigate }: { onNavigate?: () => void }) {
  const pathname = usePathname();
  const nav = useNav();
  const hydrate = useNavGroups((s) => s.hydrate);

  useEffect(() => hydrate(), [hydrate]);

  return (
    <>
      {nav.map((section) =>
        section.label === null ? (
          <div key={section.id} className="flex flex-col gap-1">
            {section.items.map((item) => (
              <NavRow
                key={item.href}
                item={item}
                active={isActive(pathname, item.href)}
                onNavigate={onNavigate}
              />
            ))}
          </div>
        ) : (
          <NavGroup
            key={section.id}
            section={section}
            pathname={pathname}
            onNavigate={onNavigate}
          />
        )
      )}
    </>
  );
}

export function SidebarNav() {
  return (
    <nav className="flex flex-col">
      <NavSections />
    </nav>
  );
}

/**
 * The *whole* menu, for the mobile sheet. MobileTabBar only has room for
 * `TAB_BAR_SLOTS` shortcuts, so without this list the rest of the admin pages
 * have no route on a phone at all.
 *
 * `onNavigate` dismisses the sheet: the target may be the current route (a tap
 * that changes nothing), and a panel that stays open over the page it just
 * "navigated" to reads as broken.
 */
export function MobileNavList({ onNavigate }: { onNavigate?: () => void }) {
  return (
    <nav className="grid">
      <NavSections onNavigate={onNavigate} />
    </nav>
  );
}

/** How many `mobile` items fit across the bottom bar. */
const TAB_BAR_SLOTS = 5;

export function MobileTabBar() {
  const pathname = usePathname();
  const nav = useNav();
  const items = navItems(nav)
    .filter((i) => i.mobile)
    .slice(0, TAB_BAR_SLOTS);
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
