import { test as base, expect, type Page } from "@playwright/test";

import { Api, PASSWORD, SUPER_ADMIN, type Account, type Org } from "./api";

/**
 * An organizer that already exists: a fresh account with an organization on the
 * `pro` plan. This is where §5.1 *ends*, so it is where every later flow starts.
 *
 * The plan is not incidental — there is no free tier, and a planless org has no
 * entitlements at all. See `Api.createOrg`.
 */
export interface Organizer {
  account: Account;
  org: Org;
}

interface Fixtures {
  api: Api;
  organizer: Organizer;
  adminPage: Page;
}

export const test = base.extend<Fixtures>({
  // Bound to Playwright's standalone request context, so arranging data never
  // pollutes the browser's cookie jar (which carries the session under test).
  api: async ({ request }, use) => {
    await use(new Api(request));
  },

  organizer: async ({ api }, use) => {
    const account = await api.registerUser("organizer");
    const org = await api.createOrg(account.token);
    await use({ account, org });
  },

  /**
   * The platform admin in a browser of their own.
   *
   * Flows like the payout queue involve two different people, and swapping the
   * session inside one context doesn't model that: the app shell keeps the
   * user it booted with (the store is memory-only and nothing re-reads the
   * identity mid-session), so the page renders as the previous person. Two
   * people, two browsers.
   */
  adminPage: async ({ browser }, use) => {
    const context = await browser.newContext();
    const page = await context.newPage();
    await signInAsSuperAdmin(page);
    await use(page);
    await context.close();
  },
});

export { expect };

/**
 * Signs a browser session in without touching the login form.
 *
 * The access token lives in memory only; the session survives a reload because
 * Laravel sets an HttpOnly refresh cookie, which AuthGate exchanges on boot.
 * `page.request` shares the browser context's cookie jar, so hitting the API's
 * login endpoint through it plants exactly that cookie — the app then boots
 * authenticated on the next navigation. Cookies ignore ports, which is why a
 * cookie set by :8000 is sent to the app on :3000.
 */
export async function signIn(page: Page, email: string, password: string = PASSWORD): Promise<void> {
  const res = await page.request.post(`${process.env.API_URL ?? "http://localhost:8000/api/v1"}/auth/login`, {
    data: { email, password },
  });

  if (!res.ok()) {
    throw new Error(`Login ${email} gagal (HTTP ${res.status()}): ${await res.text()}`);
  }
}

export function signInAsSuperAdmin(page: Page): Promise<void> {
  return signIn(page, SUPER_ADMIN.email, SUPER_ADMIN.password);
}

/** Sonner renders toasts into a live region; this is how the app confirms actions. */
export function toast(page: Page, text: string | RegExp) {
  return page.locator("[data-sonner-toast]").filter({ hasText: text });
}
