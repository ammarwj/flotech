import Link from "next/link";

import { SidebarNav, MobileTabBar } from "@/components/dashboard/sidebar-nav";
import { ThemeToggleButton } from "@/components/shared/theme-toggle-button";

function Logo() {
  return (
    <Link href="/" className="logo">
      <span className="logo-mark">
        <svg viewBox="0 0 24 24" fill="none">
          <path
            d="M5 4h14l-2 6H7l1 10"
            stroke="#fff"
            strokeWidth="2.2"
            strokeLinecap="round"
            strokeLinejoin="round"
          />
          <circle cx="9" cy="12" r="1.4" fill="#fff" />
        </svg>
      </span>
      flo<span>-event</span>
    </Link>
  );
}

export default function DashboardLayout({ children }: { children: React.ReactNode }) {
  return (
    <div className="min-h-screen md:grid md:grid-cols-[260px_1fr]">
      {/* Sidebar (desktop) */}
      <aside className="sticky top-0 hidden h-screen flex-col border-r border-border bg-[var(--bg-alt)] p-4 md:flex">
        <div className="px-2 py-2">
          <Logo />
        </div>
        <div className="mt-6 flex-1 overflow-y-auto">
          <SidebarNav />
        </div>
        <div className="mt-4 border-t border-border px-3 pt-4 text-xs text-muted-foreground">
          flo-event · v1.0
        </div>
      </aside>

      {/* Content */}
      <div className="flex min-h-screen flex-col">
        <header className="sticky top-0 z-30 flex h-16 items-center justify-between gap-4 border-b border-border bg-[color-mix(in_srgb,var(--surface)_85%,transparent)] px-5 backdrop-blur-md md:px-6">
          <div className="md:hidden">
            <Logo />
          </div>
          <span className="hidden text-sm font-medium text-muted-foreground md:inline">
            Dashboard Organizer
          </span>
          <ThemeToggleButton />
        </header>
        <main className="flex-1 px-5 py-6 pb-24 md:px-8 md:py-8 md:pb-8">{children}</main>
      </div>

      {/* Bottom tab bar (mobile) */}
      <MobileTabBar />
    </div>
  );
}
