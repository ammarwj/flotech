import { create } from "zustand";

export interface AuthUser {
  id: string;
  email: string;
  full_name: string;
  role: "super_admin" | "user";
}

interface AuthState {
  /** Access token is kept in memory only (cleared on tab close), per PRD §8.4. */
  accessToken: string | null;
  user: AuthUser | null;
  isAuthenticated: boolean;
  setAuth: (accessToken: string, user: AuthUser) => void;
  setAccessToken: (accessToken: string | null) => void;
  clearAuth: () => void;
}

export const useAuthStore = create<AuthState>((set) => ({
  accessToken: null,
  user: null,
  isAuthenticated: false,
  setAuth: (accessToken, user) => set({ accessToken, user, isAuthenticated: true }),
  setAccessToken: (accessToken) => set({ accessToken, isAuthenticated: !!accessToken }),
  clearAuth: () => set({ accessToken: null, user: null, isAuthenticated: false }),
}));
