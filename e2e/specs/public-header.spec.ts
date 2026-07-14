import { expect, signIn, test } from "../fixtures/test";

/**
 * Public pages have no AuthGate and the access token only lives in memory, so a
 * signed-in visitor used to look like a guest out here — the header invited them
 * to "Masuk" again. The session is now restored from the refresh cookie.
 */
test.describe("Header publik sadar-login", () => {
  test("tamu melihat tombol Masuk", async ({ page }) => {
    await page.goto("/");

    await expect(page.getByRole("link", { name: "Masuk", exact: true })).toBeVisible();
    await expect(page.getByRole("link", { name: "Dashboard", exact: true })).toBeHidden();
  });

  test("user yang sudah login melihat namanya, bukan ajakan mendaftar", async ({ page, organizer }) => {
    await signIn(page, organizer.account.email);
    await page.goto("/");

    await expect(page.getByRole("link", { name: "Dashboard", exact: true }).first()).toBeVisible();
    await expect(page.getByText(organizer.account.fullName).first()).toBeVisible();
    await expect(page.getByRole("link", { name: "Masuk", exact: true })).toBeHidden();

    // The CTA no longer asks someone with an account to sign up again.
    await expect(page.getByRole("link", { name: /ke dashboard/i }).first()).toBeVisible();
  });

  test("status login bertahan di halaman event publik", async ({ page, api, organizer }) => {
    const event = await api.liveEvent(organizer.account.token, organizer.org.id);
    await signIn(page, organizer.account.email);

    // The page where teams register and tickets are bought — the one place the
    // sign-in state matters most.
    await page.goto(`/${organizer.org.slug}/${event.slug}`);

    await expect(page.getByRole("link", { name: "Dashboard", exact: true }).first()).toBeVisible();
    await expect(page.getByRole("link", { name: "Masuk", exact: true })).toBeHidden();
  });

  test("keluar dari halaman publik tetap di halaman itu", async ({ page, organizer }) => {
    await signIn(page, organizer.account.email);
    await page.goto("/event");
    await expect(page.getByRole("link", { name: "Dashboard", exact: true }).first()).toBeVisible();

    await page.getByRole("button", { name: "Keluar" }).click();

    // A guest is a legitimate visitor here — no bounce to /login.
    await expect(page).toHaveURL(/\/event$/);
    await expect(page.getByRole("link", { name: "Masuk", exact: true })).toBeVisible();
  });
});
