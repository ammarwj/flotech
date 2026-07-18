"use client";

import { useEffect, useState } from "react";
import Link from "next/link";

import { PublicAuthActions } from "@/components/auth/public-auth-actions";
import { MobileMenuButton, MobileSheet, useSheetClose } from "@/components/shared/mobile-sheet";
import { ThemeToggleButton } from "@/components/shared/theme-toggle-button";
import { useAuthStore } from "@/stores/auth-store";
import { LogoMark } from "./icons";

// Anchors are absolute so they still reach the landing sections when the nav is
// rendered on another page (e.g. the event catalog).
const LINKS = [
  { href: "/#fitur", label: "Fitur" },
  { href: "/#cabang", label: "Cabang Olahraga" },
  { href: "/#cara-kerja", label: "Cara Kerja" },
  { href: "/#harga", label: "Harga" },
  { href: "/event", label: "Jelajahi Event" },
];

/** Where the desktop nav links take over from the hamburger. */
const DESKTOP_AT = 940;

export function Nav() {
  const [menuOpen, setMenuOpen] = useState(false);
  const [scrolled, setScrolled] = useState(false);

  useEffect(() => {
    const onScroll = () => setScrolled(window.scrollY > 8);
    window.addEventListener("scroll", onScroll, { passive: true });
    onScroll();
    return () => window.removeEventListener("scroll", onScroll);
  }, []);

  return (
    <header className="nav" style={{ boxShadow: scrolled ? "var(--shadow-sm)" : "none" }}>
      <div className="container nav-inner">
        <Link href="/" className="logo" aria-label="flo-event beranda">
          <span className="logo-mark">
            <LogoMark />
          </span>
          flo<span>-event</span>
        </Link>
        <nav className="nav-links">
          {LINKS.map((l) => (
            <Link key={l.href} href={l.href}>
              {l.label}
            </Link>
          ))}
        </nav>
        <div className="nav-actions">
          <ThemeToggleButton />
          <PublicAuthActions />
          {/* `hamburger` only carries the 940px breakpoint; the look comes from
              MobileMenuButton, same as the dashboard's. */}
          <MobileMenuButton
            className="hamburger"
            onClick={() => setMenuOpen(true)}
            expanded={menuOpen}
          />
        </div>
      </div>
      {menuOpen ? <Menu onClose={() => setMenuOpen(false)} /> : null}
    </header>
  );
}

function Menu({ onClose }: { onClose: () => void }) {
  // Read the store directly instead of calling useOptionalSession again: the bar
  // renders PublicAuthActions on every public page, so the session has already
  // been restored by the time this panel can be opened. A second call here would
  // fire a duplicate refresh request on cold load.
  const user = useAuthStore((s) => s.user);

  return (
    <MobileSheet
      label="Menu"
      closeAbove={DESKTOP_AT}
      onClose={onClose}
      header={
        // Same identity block as the dashboard sheet when signed in; a guest has
        // no name to show, so the panel just says what it is.
        user ? (
          <>
            <div className="truncate text-sm font-semibold">{user.full_name}</div>
            <div className="truncate text-xs text-muted-foreground">{user.email}</div>
          </>
        ) : (
          // text-base/bold, not the name block's text-sm: signed in, the header
          // is two lines and carries its own weight; a lone "Menu" at that size
          // read as a caption. Matches the app's dialog titles.
          <span className="text-base font-bold" style={{ fontFamily: "var(--font-display)" }}>
            Menu
          </span>
        )
      }
      footer={<SheetFooter />}
    >
      <SheetBody />
    </MobileSheet>
  );
}

/** Inside the sheet, so these can dismiss it *with* the exit animation. */
function SheetBody() {
  const close = useSheetClose();

  return (
    <>
      <nav className="grid">
        {LINKS.map((l) => (
          <Link
            key={l.href}
            href={l.href}
            onClick={close}
            className="border-b border-border py-3 text-[15px] font-medium text-[var(--text-2)] transition-colors last:border-b-0 hover:text-foreground"
          >
            {l.label}
          </Link>
        ))}
      </nav>

      <div className="flex items-center justify-between gap-3">
        <span className="text-sm font-medium">Tema</span>
        <ThemeToggleButton />
      </div>
    </>
  );
}

function SheetFooter() {
  const close = useSheetClose();

  return <PublicAuthActions variant="menu" onNavigate={close} />;
}
