import { AuthGate } from "@/components/auth/auth-gate";
import { SidebarNav, MobileTabBar } from "@/components/dashboard/sidebar-nav";
import { UserMenu } from "@/components/dashboard/user-menu";
import { HeaderLabel } from "@/components/dashboard/header-label";
import { Logo } from "@/components/shared/logo";
import { ThemeToggleButton } from "@/components/shared/theme-toggle-button";

export default function DashboardLayout({ children }: { children: React.ReactNode }) {
  return (
    <AuthGate>
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

      {/* Content — min-w-0 so a wide child (bracket, table) scrolls inside its
          own box instead of stretching the grid column and the page with it. */}
      <div className="flex min-h-screen min-w-0 flex-col">
        <header className="sticky top-0 z-30 flex h-16 items-center justify-between gap-4 border-b border-border bg-[color-mix(in_srgb,var(--surface)_85%,transparent)] px-5 backdrop-blur-md md:px-6">
          <div className="md:hidden">
            <Logo />
          </div>
          <HeaderLabel />
          <div className="ml-auto flex items-center gap-3">
            <ThemeToggleButton />
            <UserMenu />
          </div>
        </header>
        <main className="flex-1 px-5 py-6 pb-24 md:px-8 md:py-8 md:pb-8">{children}</main>
      </div>

        {/* Bottom tab bar (mobile) */}
        <MobileTabBar />
      </div>
    </AuthGate>
  );
}
