import { expect, signIn, test } from "../fixtures/test";

/**
 * The organizer's payment-verification queue (/organizer/events/{id}/payments).
 *
 * Scope warning, so nobody "finishes" this file later: a populated queue needs a
 * manual order, a manual order needs the platform-wide gateway switch off, and
 * flipping that here would reroute payments for every spec running in parallel
 * against the shared dev database. The queue's behaviour with rows in it —
 * approve, reject, re-upload, and the invariant that none of it credits the
 * wallet — lives in api/tests/Feature/ManualPaymentTest.php, where the flag is
 * per-test. What is worth proving in a browser is the part PHPUnit can't see:
 * that the page is reachable and honest when there is nothing to verify.
 */
test.describe("Verifikasi pembayaran manual", () => {
  test("antrean tetap dapat dibuka saat gateway hidup, dan jujur ketika kosong", async ({
    page,
    api,
    organizer,
  }) => {
    const event = await api.liveEvent(organizer.account.token, organizer.org.id);
    await signIn(page, organizer.account.email);

    // Deliberately reachable even while the gateway is up: a manual order that
    // already has a receipt never expires on its own, so hiding the queue the
    // moment the gateway comes back would strand it forever.
    await page.goto(`/organizer/events/${event.id}/payments`);

    await expect(page.getByRole("heading", { name: "Verifikasi pembayaran" })).toBeVisible();
    await expect(page.getByText("Tidak ada yang menunggu verifikasi")).toBeVisible();

    // Gateway is on, so the header says the money still lands in the organizer's
    // own account only for the manual stragglers — not that everything is manual.
    await expect(page.getByText(/Transfer manual yang menunggu diperiksa/)).toBeVisible();
  });
});
