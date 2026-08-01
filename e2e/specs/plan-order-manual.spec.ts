import { expect, test } from "../fixtures/test";
import { unique } from "../fixtures/api";

/**
 * Buying a plan by bank transfer — the half that is safe to run in parallel.
 *
 * Same split as manual-payment.spec.ts, for the same reason: a populated queue
 * needs a manual subscription, a manual subscription needs the platform-wide
 * gateway switch off, and flipping that here would reroute payments for every
 * spec running beside it on the shared dev database.
 *
 * So this file covers what is true while the gateway is *up*: the super admin's
 * queue is reachable and honest when empty, and the platform's own bank account
 * — the precondition for the whole feature — can be filled in and comes back
 * after a reload.
 *
 * The end-to-end flow (onboarding → transfer → approve → plan active) lives in
 * subscription-manual-flow.spec.ts, tagged `@gateway-off` and excluded from the
 * default run. The money rules are in api/tests/Feature/ManualSubscriptionTest.php,
 * where sqlite makes the flag per-test.
 */
// `site_settings` is a single global row, so the two tests that write it would
// race if they ran side by side.
test.describe.configure({ mode: "serial" });

test.describe("Pembelian paket lewat transfer manual — permukaan saat gateway hidup", () => {
  test("antrean verifikasi pembelian dapat dibuka dan jujur ketika kosong", async ({
    adminPage: page,
  }) => {
    // Reachable even while the gateway is up, deliberately: a plan bill that
    // already carries a receipt never expires on its own, so hiding this page
    // the moment Midtrans recovers would strand an organizer who has paid.
    await page.goto("/admin/plan-orders");

    await expect(page.getByRole("heading", { name: "Verifikasi Pembelian Paket" })).toBeVisible();

    // The line that separates this queue from an organizer's own: this money
    // lands in flo-event's account, so approving is issuing a plan on trust.
    await expect(page.getByText(/Uangnya masuk ke rekening flo-event/)).toBeVisible();

    // Nothing is pending unless a @gateway-off run is in flight, and that one
    // is serialized — so assert on the queue being *readable*, not on it being
    // empty, which would make this test hostage to the other file.
    await expect(
      page
        .getByText("Tidak ada yang menunggu verifikasi")
        .or(page.getByRole("button", { name: /Lihat bukti/ }).first()),
    ).toBeVisible();
  });

  test("menu admin memuat antrean pembelian paket", async ({ adminPage: page }) => {
    await page.goto("/admin");

    // The sidebar groups are collapsed unless they hold the active route, so at
    // /admin this one is shut. Expanding it is part of what's being tested: the
    // queue has to be *reachable* by someone navigating, not merely routable.
    await page.getByRole("button", { name: "Paket & Pembelian" }).click();

    const link = page.getByRole("link", { name: "Verifikasi Pembelian" });
    await expect(link).toBeVisible();

    await link.click();
    await expect(page).toHaveURL(/\/admin\/plan-orders$/);
  });

  test("rekening penerima pembayaran paket tersimpan dan bertahan setelah reload", async ({
    adminPage: page,
    api,
  }) => {
    // Global state: whatever the developer already has here is restored below.
    const adminToken = await api.loginAsSuperAdmin();
    const before = await api.siteSettings(adminToken);

    const holder = unique("PT Flo E2E");

    try {
      await page.goto("/admin/site-settings");

      await page.getByLabel("Nama bank").fill("BCA");
      await page.getByLabel("Kode bank").fill("014");
      await page.getByLabel("Nomor rekening").fill("9998887777");
      await page.getByLabel("Atas nama").fill(holder);

      await page.getByRole("button", { name: "Simpan" }).click();
      await expect(page.getByText("Kontak & sosmed disimpan")).toBeVisible();

      // The form drops its local draft on success, so a reload is what proves
      // the value came back from the server rather than still sitting in state.
      await page.reload();
      await expect(page.getByLabel("Nomor rekening")).toHaveValue("9998887777");
      await expect(page.getByLabel("Atas nama")).toHaveValue(holder);
    } finally {
      await restore(api, adminToken, before);
    }
  });

  /**
   * The proactive half of the rule. Switching the gateway off with no
   * destination on file means no organizer can buy a plan at all — a failure
   * that would otherwise surface as a 422 to the organizer, long after the
   * admin who caused it has moved on.
   *
   * Asserted by comparison, both ways: the warning has to appear when the
   * account is missing *and* go away once it isn't. Checking only the first
   * would pass on a warning that is simply always rendered.
   *
   * Like platform-settings.spec.ts, this never saves the switch — dragging it
   * is enough, because the warning is computed from the draft.
   */
  test("admin diperingatkan kalau mematikan gateway tanpa rekening tujuan", async ({
    adminPage: page,
    api,
  }) => {
    const adminToken = await api.loginAsSuperAdmin();
    const before = await api.siteSettings(adminToken);
    const warning = page.getByText(/Rekening penerima pembayaran paket belum diisi/);

    try {
      await api.updateSiteSettings(adminToken, {
        ...before,
        bank_name: null,
        bank_code: null,
        account_number: null,
        account_holder: null,
      });

      await page.goto("/admin/settings");
      await page.getByRole("switch", { name: "Payment gateway aktif" }).click();
      await expect(warning).toBeVisible();

      // Now fill it in and ask again — same page, same drag, opposite answer.
      await api.updateSiteSettings(adminToken, {
        ...before,
        bank_name: "BCA",
        bank_code: "014",
        account_number: "9998887777",
        account_holder: "PT Flo Event Indonesia",
      });

      await page.goto("/admin/settings");
      await page.getByRole("switch", { name: "Payment gateway aktif" }).click();
      // The platform-wide warning still shows; only the missing-account line goes.
      await expect(page.getByText("Semua organizer akan memakai transfer manual")).toBeVisible();
      await expect(warning).toBeHidden();
    } finally {
      await restore(api, adminToken, before);
    }
  });
});

/** Put the one global settings row back exactly as it was found. */
async function restore(
  api: { updateSiteSettings: (t: string, p: Record<string, unknown>) => Promise<void> },
  adminToken: string,
  before: Record<string, unknown>,
): Promise<void> {
  await api.updateSiteSettings(adminToken, {
    contact_email: before.contact_email ?? null,
    contact_phone: before.contact_phone ?? null,
    sales_email: before.sales_email ?? null,
    social_links: before.social_links ?? {},
    bank_name: before.bank_name ?? null,
    bank_code: before.bank_code ?? null,
    account_number: before.account_number ?? null,
    account_holder: before.account_holder ?? null,
  });
}
