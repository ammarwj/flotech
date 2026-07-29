import type { Page } from "@playwright/test";

import { expect, signIn, test, toast } from "../fixtures/test";
import { unique } from "../fixtures/api";

/**
 * Buying the first plan by bank transfer, end to end: onboarding → transfer →
 * super admin rejects → organizer re-uploads → super admin approves → the plan
 * is live.
 *
 * ── Why this file is opt-out of the default run ──────────────────────────────
 *
 * `payment_gateway_enabled` is platform-wide with no per-organization override,
 * so this suite normally refuses to touch it: a spec that turned it off would
 * reroute payments for every spec running beside it on the shared dev database
 * (see platform-settings.spec.ts and manual-payment.spec.ts, which both stop
 * short for exactly that reason).
 *
 * But the whole feature only exists *while* the switch is off, so stopping
 * short here would mean never proving the flow works in a browser at all. The
 * compromise is isolation by scheduling rather than by data:
 *
 *     bun run test:gateway-off
 *
 * The `@gateway-off` tag is filtered out of every other run by `grepInvert` in
 * playwright.config.ts, and that script pins `--workers=1`, so nothing else is
 * in flight while the switch is down. Never add a test here that doesn't need
 * the switch — it belongs in subscription-manual.spec.ts, which runs for free.
 *
 * `afterAll` restores the switch even when the body fails; a crashed run would
 * otherwise leave the developer's database on manual transfer, which looks
 * exactly like a bug in the app.
 */
test.describe.configure({ mode: "serial" });

test.describe("Beli paket lewat transfer manual @gateway-off", () => {
  const PLATFORM_ACCOUNT = {
    bank_name: "BCA",
    bank_code: "014",
    account_number: "9998887777",
    account_holder: "PT Flo Event Indonesia",
  };

  let adminToken: string;
  let siteSettingsBefore: Record<string, unknown>;

  test.beforeAll(async ({ browser }) => {
    // A request context of its own: the fixtures are per-test, and this has to
    // outlive them.
    const context = await browser.newContext();
    const { Api } = await import("../fixtures/api");
    const api = new Api(context.request);

    adminToken = await api.loginAsSuperAdmin();
    siteSettingsBefore = await api.siteSettings(adminToken);

    // The destination has to exist first: without it checkout is refused 422
    // rather than falling back to a gateway that is about to be switched off.
    await api.updateSiteSettings(adminToken, { ...siteSettingsBefore, ...PLATFORM_ACCOUNT });
    await api.setGateway(adminToken, false);

    await context.close();
  });

  test.afterAll(async ({ browser }) => {
    const context = await browser.newContext();
    const { Api } = await import("../fixtures/api");
    const api = new Api(context.request);

    // Order matters: the switch is the destructive half, so put it back first
    // in case restoring the settings row throws.
    await api.setGateway(adminToken, true);
    await api.updateSiteSettings(adminToken, {
      contact_email: siteSettingsBefore.contact_email ?? null,
      contact_phone: siteSettingsBefore.contact_phone ?? null,
      sales_email: siteSettingsBefore.sales_email ?? null,
      social_links: siteSettingsBefore.social_links ?? {},
      bank_name: siteSettingsBefore.bank_name ?? null,
      bank_code: siteSettingsBefore.bank_code ?? null,
      account_number: siteSettingsBefore.account_number ?? null,
      account_holder: siteSettingsBefore.account_holder ?? null,
    });

    await context.close();
  });

  test("organizer baru transfer, ditolak, unggah ulang, lalu di-acc admin", async ({
    page,
    adminPage,
    api,
  }) => {
    // A brand-new account with no organization at all — the `organizer` fixture
    // is no use here, because it hands out an org that already has a plan and
    // onboarding is precisely what we're testing.
    const account = await api.registerUser("langganan");
    const orgName = unique("EO Manual");

    await signIn(page, account.email);

    // ── Step 1: the organization ──────────────────────────────────────────
    await page.goto("/onboarding");
    await expect(page.getByRole("heading", { name: "Buat organisasi" })).toBeVisible();

    await page.getByLabel("Nama organisasi").fill(orgName);
    await page.getByRole("button", { name: "Lanjutkan" }).click();

    // ── Step 2: the plan ──────────────────────────────────────────────────
    await expect(page.getByRole("heading", { name: "Pilih paket" })).toBeVisible();
    await page.getByRole("button", { name: "Pilih paket" }).first().click();

    // ── Step 3: exists only because the gateway is off ────────────────────
    // No redirect to Midtrans, and — the invariant worth naming — no silent
    // activation either: `mock` means "no server key", never "paid".
    await expect(page.getByRole("heading", { name: "Selesaikan pembayaran" })).toBeVisible();
    await expect(page.getByText("Transfer manual ke flo-event")).toBeVisible();
    await expect(page.getByText(PLATFORM_ACCOUNT.account_number)).toBeVisible();
    await expect(page.getByText(PLATFORM_ACCOUNT.account_holder)).toBeVisible();

    await uploadProof(page);
    await expect(page.getByText("Bukti terkirim, menunggu verifikasi admin")).toBeVisible();

    // ── The wait: in, but entitled to nothing ─────────────────────────────
    await page.getByRole("button", { name: "Lihat dashboard" }).click();
    await expect(page).toHaveURL(/\/organizer$/);
    await expect(page.getByText("Pembayaran paketmu sedang diverifikasi")).toBeVisible();

    // No plan means no entitlements at all — not "unlimited". The create-event
    // page is where an organizer first meets that, and the exact wording is
    // load-bearing: EventLimitNotice has a second branch for "cap reached",
    // which must not tell someone to cancel events they don't have.
    await page.goto("/organizer/events/new");
    await expect(page.getByText("Organisasimu belum punya paket")).toBeVisible();
    // The notice replaces the form, not the page header — so the form is what
    // proves the gate held.
    await expect(page.getByLabel("Nama event")).toBeHidden();

    // ── The super admin turns the receipt down ────────────────────────────
    await adminPage.goto("/admin/subscriptions");
    await expect(adminPage.getByText(orgName)).toBeVisible();

    await openReview(adminPage, orgName);
    const dialog = adminPage.getByRole("dialog");

    // The verdict is typed on the same screen as the receipt — that is the
    // point of the dialog, so assert the bill is actually in front of them.
    //
    // On the receipt itself, assert the *link*, not the rendered <img>. The
    // object is uploaded by then, but the public r2.dev URL that renders it
    // propagates on Cloudflare's schedule, so waiting for onLoad makes this
    // test flaky on a CDN we don't control. That the dialog was handed a proof
    // URL is ours; that a CDN has caught up is not.
    await expect(dialog.getByRole("link", { name: "Tab baru" })).toHaveAttribute(
      "href",
      /payment-proofs\/.+\.png$/,
    );
    // Twice over: once in the dialog's subtitle, once in the details list.
    await expect(dialog.getByText(orgName).first()).toBeVisible();

    await dialog.getByRole("button", { name: "Tolak", exact: true }).click();
    await dialog.getByPlaceholder("Alasan penolakan (dilihat organizer)").fill("Nominal tidak cocok.");
    await dialog.getByRole("button", { name: "Kirim penolakan" }).click();
    await expect(toast(adminPage, /bukti ditolak/i)).toBeVisible();

    // Rejecting is a verdict on the receipt, not on the payment, so the bill
    // goes back to the organizer rather than being cancelled.
    await expect(adminPage.getByText(orgName)).toBeHidden();

    // ── The organizer sees why, and replaces it ───────────────────────────
    await page.goto("/organizer/subscription");
    await expect(page.getByText("Bukti sebelumnya ditolak")).toBeVisible();
    await expect(page.getByText("Nominal tidak cocok.")).toBeVisible();

    await uploadProof(page);
    await expect(page.getByText("Bukti terkirim, menunggu verifikasi admin")).toBeVisible();

    // ── Approved: the plan finally lands ──────────────────────────────────
    await adminPage.goto("/admin/subscriptions");
    await openReview(adminPage, orgName);
    await adminPage.getByRole("dialog").getByRole("button", { name: "Terima pembayaran" }).click();
    await expect(toast(adminPage, /paket sudah aktif/i)).toBeVisible();

    await page.goto("/organizer/subscription");
    await expect(page.getByText("Tanpa paket")).toBeHidden();
    await expect(page.getByText("Transfer manual ke flo-event")).toBeHidden();
    // A receipt is only issued once the money is in, so its button is the
    // cheapest proof the bill actually settled.
    await expect(page.getByRole("button", { name: "Kwitansi" })).toBeVisible();

    // The banner is keyed off the org having no plan; it must let go now.
    await page.goto("/organizer");
    await expect(page.getByText("Pembayaran paketmu sedang diverifikasi")).toBeHidden();
  });
});

/**
 * The receipt upload. The input is hidden behind a styled label, so the file is
 * set on the input directly rather than clicking through it.
 *
 * This writes a real 16×16 PNG to the `payment-proofs/` bucket, the same way
 * wallet-payout.spec.ts writes its transfer proof.
 */
async function uploadProof(page: Page): Promise<void> {
  await page.locator('input[type="file"]').setInputFiles("fixtures/transfer-proof.png");
}

/**
 * Open the review dialog for one organization's bill.
 *
 * The queue is platform-wide, so scope to the smallest card carrying *this*
 * organization's name — filtering on the name alone matches every ancestor div
 * up to <body>. Same shape as `payoutRow` in wallet-payout.spec.ts.
 *
 * A row has exactly one button now: the receipt and the verdict live together
 * in the dialog, because a decision made on a screen that no longer shows the
 * thing being judged is not a decision.
 */
async function openReview(page: Page, orgName: string): Promise<void> {
  const trigger = page.getByRole("button", { name: /Lihat bukti/ });

  await page
    .locator("div")
    .filter({ hasText: orgName })
    .filter({ has: trigger })
    .last()
    .getByRole("button", { name: /Lihat bukti/ })
    .click();
}
