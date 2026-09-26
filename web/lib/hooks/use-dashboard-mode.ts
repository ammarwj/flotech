"use client";

import { useEffect } from "react";
import { usePathname } from "next/navigation";
import { create } from "zustand";

import { useAuthStore } from "@/stores/auth-store";
import type { DashboardModeValue } from "@/types/api";

/**
 * The dashboard has two hats a regular user can wear: organizer (their own
 * events) and participant (teams they registered elsewhere). The *current* mode
 * is derived from the URL rather than stored, so a deep link or refresh lands in
 * the right one and there is no state to keep in sync with the router.
 *
 * Which mode a fresh login opens in is a separate thing: it comes from the
 * server (`user.default_mode`), written every time the switcher is used.
 *
 * `officiating` is a third mode but not a third hat: nobody chooses it, an
 * organizer puts them there. It has no button in the switcher (see
 * DASHBOARD_MODES) — the only way in is a URL under /officiating, which the
 * server refuses to anyone with no personnel row.
 *
 * A route that belongs to no mode at all (`/account`) falls back to the stored
 * hat rather than to organizer — see `useDashboardMode()`.
 *
 * Aliased to the stored column's type rather than spelled out again: the two are
 * the same set, and written twice one of them eventually gains a member the
 * other lacks — which shows up as a `Record<DashboardMode, …>` lookup returning
 * undefined for a value the server really does send.
 */
export type DashboardMode = DashboardModeValue;

export const MODE_HOME: Record<DashboardMode, string> = {
  organizer: "/organizer",
  participant: "/participant",
  officiating: "/officiating",
};

export const MODE_LABEL: Record<DashboardMode, string> = {
  organizer: "Dashboard Organizer",
  participant: "Area Peserta",
  officiating: "Area Petugas",
};

/** What fits on a segmented button; the long label stays as the accessible name. */
export const MODE_SHORT_LABEL: Record<DashboardMode, string> = {
  organizer: "Organizer",
  participant: "Peserta",
  officiating: "Petugas",
};

/**
 * Petugas dan tidak lebih dari itu.
 *
 * `account_types` diturunkan dari organisasi & tim saja, jadi ia kosong tepat
 * ketika akun ini tidak punya keduanya — bentuk akun yang lahir dari undangan
 * petugas. Seorang wasit yang juga memanajeri tim **tidak** lolos uji ini; dia
 * memang punya dua topi dan tetap memegang switcher.
 *
 * Argumennya struktural supaya `AuthUser` dan `AdminUser` sama-sama masuk tanpa
 * cast: yang dibaca cuma panjang kedua daftar. Keduanya opsional di salah satu
 * tipe — "tidak di-load" dan "tidak punya" adalah absen yang sama, dan absen di
 * sini dibaca 0.
 */
export function isCrewOnly(user: {
  officiating?: readonly unknown[] | null;
  account_types?: readonly unknown[] | null;
}): boolean {
  return (user.officiating?.length ?? 0) > 0 && (user.account_types?.length ?? 0) === 0;
}

/**
 * Mode yang seharusnya dibukakan untuk sebuah akun — sumber tunggal untuk
 * halaman pendaratannya (`MODE_HOME`) **dan** kalimat yang menyebutkannya
 * (`MODE_LABEL`).
 *
 * `default_mode` sendirian tidak cukup, dan bukan karena server lalai:
 * `EventPersonnelService` hanya menulis `'officiating'` pada akun yang ia
 * **buat**. Baris petugas yang ditempelkan ke email yang sudah ada tetap membawa
 * mode lamanya, jadi `MODE_HOME[default_mode]` mengirim wasit itu ke dashboard
 * organizer — dan karena dia tidak punya organisasi, OrganizerLayout
 * meneruskannya lagi ke /onboarding. Penugasannya yang menjawab, bukan kolom
 * preferensinya.
 *
 * Satu fungsi, bukan aturan yang disalin ke tiap pemanggil: tujuan yang dituju
 * "Login sebagai", kalimat di dialog konfirmasinya, dan tujuan yang dipakai
 * AdminLayout saat memulangkan sesi impersonasi dari /admin harus menjawab sama
 * — tiga pembaca yang berselisih menghasilkan dialog yang menjanjikan satu
 * halaman lalu mendarat di halaman lain.
 */
export function dashboardModeFor(user: {
  default_mode?: DashboardMode | null;
  officiating?: readonly unknown[] | null;
  account_types?: readonly unknown[] | null;
}): DashboardMode {
  if (isCrewOnly(user)) return "officiating";
  return user.default_mode ?? "organizer";
}

/**
 * A mode a user can *choose* — in the switcher, or on the register form.
 *
 * Narrower than `DashboardMode` on purpose, and it is the type that keeps the
 * two apart: anything keyed by the choosable set (register's MODE_PITCH) would
 * otherwise have to invent copy for `officiating`, and copy that exists reads as
 * an offer. Widening this is how a future fourth mode becomes a compile error in
 * every screen that has to have something to say about it.
 */
export type ChoosableMode = Extract<DashboardMode, "organizer" | "participant">;

/**
 * What the switcher renders — deliberately still two.
 *
 * A third button would offer every account a mode almost none of them can
 * enter. Crew reach their area by landing there, and ModeSwitcher gives them a
 * plain label instead, the same way it does for super admins.
 */
export const DASHBOARD_MODES: ChoosableMode[] = ["organizer", "participant"];

/**
 * The homes `modeFromPath()` tries, listed rather than derived from `MODE_HOME`
 * so that adding a mode is a compile error here instead of a home that silently
 * matches nothing. The three prefixes are disjoint, so the order is arbitrary —
 * unlike the old pathname chain, which needed officiating checked first because
 * anything unmatched fell through to organizer.
 */
const MATCH_ORDER: DashboardMode[] = ["officiating", "participant", "organizer"];

/**
 * Which mode a dashboard path belongs to, or `null` for one that belongs to none
 * of them.
 *
 * `/account` is the only such route today, and deliberately so — there is one
 * account behind every hat, so it cannot sit inside a mode's subtree. Matched
 * against `MODE_HOME` rather than a second list of prefixes, so a new mode can't
 * get a home without also being recognised here.
 */
export function modeFromPath(pathname: string): DashboardMode | null {
  for (const mode of MATCH_ORDER) {
    const home = MODE_HOME[mode];
    if (pathname === home || pathname.startsWith(home + "/")) return mode;
  }
  return null;
}

/**
 * The last mode a path actually resolved to, so a route that belongs to none
 * (`/account`) can stay in the hat the user was already wearing.
 *
 * Module-level rather than `useState`, for the same reason the nav group prefs in
 * `sidebar-nav` are: the desktop sidebar is `hidden md:flex` and so stays
 * *mounted* beside the mobile sheet, and several components read this in one
 * render — per-component copies would disagree about which hat is on.
 *
 * Stamped with the user it was learnt from, and that is load-bearing: a module
 * global outlives `clearAuth()`, so an admin who impersonated a manager, or a
 * logout and login in the same tab, would otherwise hand the next account the
 * previous one's hat. Mismatched stamp = no memory, which is the cold-load answer
 * anyway.
 *
 * Deliberately not persisted. It only has to survive a click within a session,
 * `default_mode` already covers a cold load, and reading storage in an
 * initializer renders differently on the server.
 */
const useLastMode = create<{
  userId: string | null;
  mode: DashboardMode | null;
  remember: (userId: string, mode: DashboardMode) => void;
}>((set) => ({
  userId: null,
  mode: null,
  // No-op on an unchanged pair: written from an effect that runs in every
  // component reading the hook.
  remember: (userId, mode) =>
    set((s) => (s.userId === userId && s.mode === mode ? s : { userId, mode })),
}));

export function useDashboardMode(): DashboardMode {
  const pathname = usePathname();
  const fromPath = modeFromPath(pathname);
  const remember = useLastMode((s) => s.remember);
  const userId = useAuthStore((s) => s.user?.id);
  // Falls back to the hat worn last rather than to organizer, which is what this
  // used to do — so /account, the one page that says it applies to every mode,
  // handed a team manager the organizer sidebar, switcher and tab bar, and a crew
  // account the organizer menu it has no access to.
  const last = useLastMode((s) => (userId && s.userId === userId ? s.mode : null));
  // Last resort, and only reached on a cold load of /account, where there is no
  // previous path to have learnt from. Not enough on its own: the switcher is
  // what writes `default_mode`, so anyone who reached /participant by bookmark or
  // deep link still has "organizer" stored — that is the case `last` covers.
  //
  // `user` lands a tick after the token (see AuthGate), so that cold load reads
  // "organizer" for one tick and then corrects; a tap on "Akun" never sees it.
  const stored = useAuthStore((s) => s.user?.default_mode);

  useEffect(() => {
    if (fromPath && userId) remember(userId, fromPath);
  }, [fromPath, userId, remember]);

  return fromPath ?? last ?? stored ?? "organizer";
}
