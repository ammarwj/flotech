import { AuthGate } from "@/components/auth/auth-gate";
import { UserMenu } from "@/components/dashboard/user-menu";
import { Logo } from "@/components/shared/logo";
import { ThemeToggleButton } from "@/components/shared/theme-toggle-button";

/**
 * Chrome-free shell for onboarding: authenticated, but deliberately without the
 * sidebar/tab bar. A user who has not created an organization yet has nothing
 * to navigate to, so the only ways out are finishing the flow or logging out.
 */
export default function OnboardingLayout({ children }: { children: React.ReactNode }) {
  return (
    <AuthGate>
      <div className="flex min-h-screen flex-col">
        <header className="sticky top-0 z-30 flex h-16 items-center justify-between gap-4 border-b border-border bg-[color-mix(in_srgb,var(--surface)_85%,transparent)] px-5 backdrop-blur-md md:px-8">
          <Logo />
          <div className="ml-auto flex items-center gap-3">
            <ThemeToggleButton />
            <UserMenu />
          </div>
        </header>
        <main className="flex-1 px-5 py-8 md:px-8">{children}</main>
      </div>
    </AuthGate>
  );
}
