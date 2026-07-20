import { create } from "zustand";

import type { DashboardMode } from "@/lib/hooks/use-dashboard-mode";
import type { AuthUser } from "@/types/api";

export type { AuthUser };

const IMPERSONATION_KEY = "flo:impersonating-user-id";

/**
 * Only the *target user id* is persisted — never the impersonation token, which
 * stays in memory like every other access token (PRD §8.4). On boot `AuthGate`
 * mints a fresh one from this id using the admin's refresh cookie, which the
 * impersonation flow never touches. sessionStorage rather than localStorage so
 * it is scoped to the tab and dies with it.
 */
export function readPendingImpersonation(): string | null {
  if (typeof window === "undefined") return null;
  return window.sessionStorage.getItem(IMPERSONATION_KEY);
}

export function writePendingImpersonation(id: string | null): void {
  if (typeof window === "undefined") return;
  if (id) window.sessionStorage.setItem(IMPERSONATION_KEY, id);
  else window.sessionStorage.removeItem(IMPERSONATION_KEY);
}

interface AuthState {
  /** Access token is kept in memory only (cleared on tab close), per PRD §8.4. */
  accessToken: string | null;
  user: AuthUser | null;
  isAuthenticated: boolean;
  /**
   * True while a super admin is "logged in as" someone else. The admin's own
   * refresh cookie is untouched during this, so leaving is a plain refresh.
   * A reload does *not* exit: the target id survives in sessionStorage and
   * `AuthGate` re-enters impersonation before rendering the shell.
   */
  impersonating: boolean;
  setAuth: (accessToken: string, user: AuthUser) => void;
  setAccessToken: (accessToken: string | null) => void;
  setDefaultMode: (mode: DashboardMode) => void;
  startImpersonation: (accessToken: string, user: AuthUser) => void;
  stopImpersonation: () => void;
  clearAuth: () => void;
}

export const useAuthStore = create<AuthState>((set) => ({
  accessToken: null,
  user: null,
  isAuthenticated: false,
  impersonating: false,
  setAuth: (accessToken, user) => set({ accessToken, user, isAuthenticated: true }),
  setAccessToken: (accessToken) => set({ accessToken, isAuthenticated: !!accessToken }),
  // Mirrors what the switcher just persisted, so the UI doesn't wait on the PATCH.
  setDefaultMode: (mode) =>
    set((s) => (s.user ? { user: { ...s.user, default_mode: mode } } : s)),
  // The sessionStorage side effects live here rather than at the call sites so
  // every exit path clears them — including clearAuth() from logout and from
  // the 401 interceptor. A stale id left behind would re-enter impersonation on
  // the next boot.
  startImpersonation: (accessToken, user) => {
    writePendingImpersonation(user.id);
    set({ accessToken, user, isAuthenticated: true, impersonating: true });
  },
  // Only clears the flag — the caller swaps the token back to the admin's.
  stopImpersonation: () => {
    writePendingImpersonation(null);
    set({ impersonating: false });
  },
  clearAuth: () => {
    writePendingImpersonation(null);
    set({ accessToken: null, user: null, isAuthenticated: false, impersonating: false });
  },
}));
