import { create } from "zustand";

import type { DashboardMode } from "@/lib/hooks/use-dashboard-mode";
import type { AuthUser } from "@/types/api";

export type { AuthUser };

interface AuthState {
  /** Access token is kept in memory only (cleared on tab close), per PRD §8.4. */
  accessToken: string | null;
  user: AuthUser | null;
  isAuthenticated: boolean;
  /**
   * True while a super admin is "logged in as" someone else. The admin's own
   * refresh cookie is untouched during this, so leaving is a plain refresh —
   * and because this store isn't persisted, a reload also exits impersonation.
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
  startImpersonation: (accessToken, user) =>
    set({ accessToken, user, isAuthenticated: true, impersonating: true }),
  // Only clears the flag — the caller swaps the token back to the admin's.
  stopImpersonation: () => set({ impersonating: false }),
  clearAuth: () =>
    set({ accessToken: null, user: null, isAuthenticated: false, impersonating: false }),
}));
