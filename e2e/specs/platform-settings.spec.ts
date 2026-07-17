import { expect, test } from "../fixtures/test";

/**
 * The payment-gateway kill switch (/admin/settings).
 *
 * Note what this spec does *not* do: it never saves the switch. The setting is
 * platform-wide — there is no per-organization override — so persisting "gateway
 * off" would reroute payments for every spec running beside it, on the shared
 * dev database, mid-run. It is the one piece of state this suite cannot isolate.
 *
 * That costs nothing here, because the warning is computed from the *draft*
 * (see `gatewayOff` in admin/settings/page.tsx): dragging the switch is enough
 * to prove the admin is told what they're about to do. The money rules behind
 * the switch — manual orders never crediting the wallet, expiry, who may
 * approve a receipt — are asserted in api/tests/Feature/ManualPaymentTest.php,
 * where sqlite makes the flag per-test.
 */
test.describe("Pengaturan platform — saklar payment gateway", () => {
  // `organizer` is requested for its side effect only — it creates a fresh org
  // with no bank account, so the "belum punya rekening" count below is never
  // zero by luck. Nothing in the test body needs the value.
  test("mematikan gateway memperingatkan admin sebelum disimpan", async ({
    adminPage: page,
    organizer: _organizer,
  }) => {
    await page.goto("/admin/settings");

    const gateway = page.getByRole("switch", { name: "Payment gateway aktif" });
    await expect(gateway).toBeVisible();
    // Gateway is on by default (config/payments.php), so there is no warning yet.
    await expect(gateway).toBeChecked();
    await expect(page.getByText("Semua organizer akan memakai transfer manual")).toBeHidden();

    await gateway.click();

    // Switching it off is a platform-wide decision; say so before it's saved.
    await expect(page.getByText("Semua organizer akan memakai transfer manual")).toBeVisible();
    await expect(gateway).not.toBeChecked();

    // An org with no bank account cannot be paid at all once the gateway is off,
    // so the admin sees how many before flipping it, not after the tickets land.
    await expect(page.getByText(/\d+ organisasi belum punya rekening/)).toBeVisible();
  });

  test("aturan pencairan tetap input angka, bukan saklar", async ({ adminPage: page }) => {
    await page.goto("/admin/settings");

    // `bool` was added to PlatformSettings alongside money/int; the numeric
    // settings must not have been swept into switches with it.
    await expect(page.getByLabel("Minimal penarikan")).toHaveValue(/^\d+$/);
    await expect(page.getByLabel("Biaya admin per penarikan")).toHaveValue(/^\d+$/);
    await expect(page.getByLabel("Masa tahan setelah event selesai (hari)")).toHaveValue(/^\d+$/);
  });
});
