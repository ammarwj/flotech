import type { Page, Request } from "@playwright/test";

import { expect, signIn, test, toast } from "../fixtures/test";
import { unique } from "../fixtures/api";
import { largeBitmap } from "../fixtures/large-image";

/**
 * Buying the first plan by bank transfer, end to end: onboarding → create-event
 * → transfer → super admin rejects → organizer re-uploads → super admin
 * approves → the credit is ready to spend.
 *
 * Onboarding is one step now, and buying is no longer part of it. An
 * organization exists without a plan and buys per event, so the picker lives on
 * the create-event page — which is where onboarding drops you, and where this
 * spec therefore has to go to reach a payment at all.
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
 * the switch — it belongs in plan-order-manual.spec.ts, which runs for free.
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
    const account = await api.registerUser("paket");
    const orgName = unique("EO Manual");

    await signIn(page, account.email);

    // ── The organization, and that is the whole of onboarding ─────────────
    await page.goto("/onboarding");
    await expect(page.getByRole("heading", { name: "Buat organisasi" })).toBeVisible();

    await page.getByLabel("Nama organisasi").fill(orgName);
    await page.getByRole("button", { name: "Selesai" }).click();

    // Straight to creating an event — an organization needs to buy nothing to
    // exist. No plan means no entitlements at all, not "unlimited", so the
    // picker stands in for the form rather than beside it: the hidden form is
    // what proves the gate held.
    await expect(page).toHaveURL(/\/organizer\/events\/new$/);
    await expect(page.getByRole("heading", { name: "Pilih paket untuk event ini" })).toBeVisible();
    await expect(page.getByLabel("Nama event")).toBeHidden();

    // ── Buying, with the gateway off ──────────────────────────────────────
    // No redirect to Midtrans, and — the invariant worth naming — no silent
    // activation either: `mock` means "no server key", never "paid". The
    // transfer panel lives on the billing page, so a checkout that settled on
    // the spot would land somewhere else entirely and this would fail.
    await page.getByRole("button", { name: "Beli paket" }).first().click();

    await expect(page).toHaveURL(/\/organizer\/billing$/);
    await expect(page.getByText("Transfer manual ke flo-event")).toBeVisible();
    await expect(page.getByText(PLATFORM_ACCOUNT.account_number)).toBeVisible();
    await expect(page.getByText(PLATFORM_ACCOUNT.account_holder)).toBeVisible();

    await uploadProof(page);
    await expect(page.getByText("Bukti terkirim, menunggu verifikasi admin")).toBeVisible();

    // ── The wait: in, but entitled to nothing ─────────────────────────────
    await page.goto("/organizer");
    await expect(page.getByText("Pembayaran paketmu sedang diverifikasi")).toBeVisible();

    // An unpaid bill is not a credit. The picker has to still be standing —
    // otherwise an organizer could create an event on money that never arrived.
    await page.goto("/organizer/events/new");
    await expect(page.getByRole("heading", { name: "Pilih paket untuk event ini" })).toBeVisible();
    await expect(page.getByLabel("Nama event")).toBeHidden();

    // ── The super admin turns the receipt down ────────────────────────────
    await adminPage.goto("/admin/plan-orders");
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
    //
    // `.webp` while `uploadProof` picks a `.png` is the assertion, not a
    // detail: the extension changing between the file chosen and the object
    // stored is the only end-to-end evidence that the receipt went through the
    // WebP pipeline rather than being PUT to the bucket untouched. Matching
    // `.+\.` alone would pass either way.
    await expect(dialog.getByRole("link", { name: "Tab baru" })).toHaveAttribute(
      "href",
      /payment-proofs\/.+\.webp$/,
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
    await page.goto("/organizer/billing");
    await expect(page.getByText("Bukti sebelumnya ditolak")).toBeVisible();
    await expect(page.getByText("Nominal tidak cocok.")).toBeVisible();

    await uploadProof(page);
    await expect(page.getByText("Bukti terkirim, menunggu verifikasi admin")).toBeVisible();

    // ── Approved: the plan finally lands ──────────────────────────────────
    await adminPage.goto("/admin/plan-orders");
    await openReview(adminPage, orgName);
    await adminPage.getByRole("dialog").getByRole("button", { name: "Terima pembayaran" }).click();
    await expect(toast(adminPage, /paket sudah aktif/i)).toBeVisible();

    await page.goto("/organizer/billing");
    await expect(page.getByText("Transfer manual ke flo-event")).toBeHidden();
    // What was bought is a credit, not an entitlement: it shows up waiting for
    // an event rather than switching anything on. That section only renders for
    // orders that are paid *and* unspent, so it is the assertion that separates
    // "settled" from "activated".
    await expect(page.getByRole("heading", { name: "Paket siap dipakai" })).toBeVisible();
    // A receipt is only issued once the money is in, so its button is the
    // cheapest proof the bill actually settled.
    await expect(page.getByRole("button", { name: "Kwitansi" })).toBeVisible();

    // The banner is keyed off a payment awaiting verification; it must let go.
    await page.goto("/organizer");
    await expect(page.getByText("Pembayaran paketmu sedang diverifikasi")).toBeHidden();

    // Stops here on purpose. That the credit is then *spendable* — picker out,
    // form in — is auth-onboarding.spec.ts's job, and it proves it on a fresh
    // session. Repeating it here would only add a ninth full page load to a
    // session that has already been reloaded eight times, and the access token
    // lives in memory: every goto re-runs the refresh dance, which is a poor
    // thing to lean on for an assertion that belongs elsewhere anyway.
  });

  /**
   * What the receipt upload does to the file on its way out.
   *
   * Here rather than in a spec of its own because the upload box only exists
   * while the gateway is off, and this file already owns that switch. It is
   * about the pipeline, not the plan, so it stops at the payment step.
   *
   * Two things PHPUnit structurally cannot see: `compressToWebp` is a browser
   * API (canvas → `toBlob`), and the client-side format guard never reaches the
   * server at all. The endpoint's own re-encode is covered by the assertion on
   * the stored `.webp` URL in the test above.
   */
  test("bukti dikompres jadi WebP sebelum dikirim, dan non-gambar ditolak di klien", async ({
    page,
    api,
  }) => {
    const account = await api.registerUser("bukti");
    await signIn(page, account.email);

    // Record every upload attempt, so "nothing was sent" is provable rather
    // than inferred from the absence of a success message.
    const uploads: Request[] = [];
    page.on("request", (req) => {
      if (req.url().includes("/uploads/")) uploads.push(req);
    });

    // Watch what the client hands to the transport, not what goes on the wire:
    // Chromium streams multipart uploads, so `postDataBuffer()` on the request
    // is null and the bytes are unreadable from the Node side. `FormData.append`
    // is where `uploadImage` puts the file, and patching it sees the File object
    // itself — name, type and compressed size — regardless of whether axios ends
    // up using XHR or fetch.
    await page.addInitScript(() => {
      const appended: { name: string; type: string; size: number }[] = [];
      (window as unknown as Record<string, unknown>).__appendedFiles = appended;
      const original = FormData.prototype.append;
      FormData.prototype.append = function (this: FormData, ...args: unknown[]) {
        const value = args[1];
        if (value instanceof File) {
          appended.push({ name: value.name, type: value.type, size: value.size });
        }
        return (original as (...a: unknown[]) => void).apply(this, args);
      } as typeof FormData.prototype.append;
    });

    await page.goto("/onboarding");
    await page.getByLabel("Nama organisasi").fill(unique("EO Bukti"));
    await page.getByRole("button", { name: "Selesai" }).click();
    await page.getByRole("button", { name: "Beli paket" }).first().click();
    await expect(page.getByText("Transfer manual ke flo-event")).toBeVisible();

    const input = page.locator('input[type="file"]');

    // ── A PDF is refused before any request is made ───────────────────────
    // `accept="image/*"` only filters the picker's dialog; drag-and-drop and
    // any non-browser client walk straight past it, so the guard in the change
    // handler is the real one. setInputFiles ignores `accept` too, which is
    // exactly what makes it able to test that guard.
    await input.setInputFiles({
      name: "bukti.pdf",
      mimeType: "application/pdf",
      buffer: Buffer.from("%PDF-1.4"),
    });
    await expect(page.getByText("Bukti harus berupa gambar (JPG, PNG, atau WebP).")).toBeVisible();
    expect(uploads, "a rejected file must not reach the network").toHaveLength(0);

    // ── An oversized image is downscaled and re-encoded ───────────────────
    const source = largeBitmap();
    await input.setInputFiles(source);

    await expect(page.getByText("Bukti terkirim, menunggu verifikasi admin")).toBeVisible();
    // The guard's message clears once a valid file goes through.
    await expect(page.getByText("Bukti harus berupa gambar (JPG, PNG, atau WebP).")).toBeHidden();

    expect(uploads).toHaveLength(1);
    const [upload] = uploads;

    // The pipeline switch itself: receipts used to take a presigned URL and PUT
    // the raw file straight to the bucket, which is the one path that skipped
    // every resize. `/uploads/sign` must not be involved any more.
    expect(upload.url()).toContain("/uploads/image");
    expect(upload.method()).toBe("POST");

    // What the client actually produced. This is the only evidence available for
    // the *client* leg: the stored URL is always `.webp` because the endpoint
    // mints the key itself, so the URL cannot tell a converted upload from a
    // re-encoded one.
    const sent = await page.evaluate(
      () => (window as unknown as { __appendedFiles: { name: string; type: string; size: number }[] }).__appendedFiles,
    );
    expect(sent).toHaveLength(1);

    // Converted, not merely shrunk.
    expect(sent[0].type).toBe("image/webp");
    expect(sent[0].name).toBe("struk-transfer.webp");

    // Compare the bytes sent against the bytes picked — the assertion the 12 MB
    // BMP exists for. An assertion that the upload merely "succeeded" would pass
    // just as well with the original file on the wire.
    expect(sent[0].size).toBeGreaterThan(0);
    expect(sent[0].size).toBeLessThan(source.buffer.length / 10);
    // And it now fits the endpoint's own `max:5120` rule, which the raw file
    // (~12 MB) would have failed outright.
    expect(sent[0].size).toBeLessThan(5 * 1024 * 1024);
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
