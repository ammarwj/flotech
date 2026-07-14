import { fileURLToPath } from "node:url";
import path from "node:path";
import type { Page } from "@playwright/test";

import { expect, signIn, test, toast } from "../fixtures/test";

const PROOF_IMAGE = path.join(path.dirname(fileURLToPath(import.meta.url)), "..", "fixtures", "transfer-proof.png");

/**
 * PRD §5.7 — Organizer tarik dana, dan §5.8 — super admin memproses pencairan.
 *
 * Money never arrives here through a real payment: that needs a Midtrans
 * webhook. The wallet is funded through the platform admin's ledger adjustment
 * (the supported gateway-less path), because what these flows are about is the
 * payout, not the earning.
 *
 * The organizer drives `page`; the platform admin drives `adminPage`, a separate
 * browser — they are two different people.
 */
test.describe("§5.7 & §5.8 Dompet & penarikan dana", () => {
  test("tombol tarik dana mati dengan alasan eksplisit saat belum ada rekening", async ({
    page,
    api,
    organizer,
  }) => {
    const admin = await api.loginAsSuperAdmin();
    await api.creditWallet(admin, organizer.account.token, organizer.org.id, 1_000_000);

    await signIn(page, organizer.account.email);
    await page.goto("/organizer/wallet");

    // The PRD is explicit: say *why* it's off, don't just grey it out.
    await expect(page.getByRole("button", { name: /tarik dana/i })).toBeDisabled();
    await expect(page.getByText("Tambahkan rekening bank dulu.")).toBeVisible();
  });

  test("saldo di bawah minimum mengunci penarikan", async ({ page, api, organizer }) => {
    const admin = await api.loginAsSuperAdmin();
    const rules = await api.walletRules(organizer.account.token, organizer.org.id);
    // One rupiah short of minimum + fee: the boundary the rule actually guards.
    const short = rules.minimum_withdrawal + rules.admin_fee - 1;
    await api.creditWallet(admin, organizer.account.token, organizer.org.id, short);

    await signIn(page, organizer.account.email);
    await page.goto("/organizer/wallet");
    await addBankAccount(page);

    await expect(page.getByRole("button", { name: /tarik dana/i })).toBeDisabled();
    await expect(page.getByText(/belum mencapai/i)).toBeVisible();
  });

  test("alur penuh: organizer ajukan → admin proses → selesai dengan bukti transfer", async ({
    page,
    adminPage,
    api,
    organizer,
  }) => {
    const admin = await api.loginAsSuperAdmin();
    const rules = await api.walletRules(organizer.account.token, organizer.org.id);
    const amount = rules.minimum_withdrawal;
    await api.creditWallet(admin, organizer.account.token, organizer.org.id, amount + rules.admin_fee + 50_000);

    // ---- §5.7 Organizer mengajukan penarikan ----
    await signIn(page, organizer.account.email);
    await page.goto("/organizer/wallet");
    await addBankAccount(page);
    await requestWithdrawal(page, amount);

    // Funds leave the available balance the moment it's filed — held, not spent.
    await expect(page.getByText("Menunggu Diproses").first()).toBeVisible();

    // ---- §5.8 Super admin memproses antrian ----
    await adminPage.goto("/admin/withdrawals");
    await payoutRow(adminPage, organizer.org.name, "Proses")
      .getByRole("button", { name: "Proses", exact: true })
      .click();
    await expect(toast(adminPage, /sedang diproses/i)).toBeVisible();

    await payoutRow(adminPage, organizer.org.name, "Tandai Selesai")
      .getByRole("button", { name: "Tandai Selesai", exact: true })
      .click();

    // Proof is mandatory — a payout marked done with no receipt is unauditable.
    await adminPage.getByLabel("Bukti transfer").setInputFiles(PROOF_IMAGE);
    await adminPage.getByRole("button", { name: /tandai selesai/i }).last().click();
    await expect(toast(adminPage, /ditandai selesai/i)).toBeVisible();

    // ---- Organizer melihat statusnya berubah ----
    await page.reload();
    await expect(page.getByText("Selesai").first()).toBeVisible();
  });

  test("admin menolak penarikan — dana kembali ke saldo tersedia", async ({
    page,
    adminPage,
    api,
    organizer,
  }) => {
    const admin = await api.loginAsSuperAdmin();
    const rules = await api.walletRules(organizer.account.token, organizer.org.id);
    await api.creditWallet(
      admin,
      organizer.account.token,
      organizer.org.id,
      rules.minimum_withdrawal + rules.admin_fee,
    );

    await signIn(page, organizer.account.email);
    await page.goto("/organizer/wallet");
    await addBankAccount(page);
    await requestWithdrawal(page, rules.minimum_withdrawal);

    // The reason is collected in a native prompt.
    adminPage.once("dialog", (d) => d.accept("Rekening tidak valid"));
    await adminPage.goto("/admin/withdrawals");
    await payoutRow(adminPage, organizer.org.name, "Tolak")
      .getByRole("button", { name: "Tolak", exact: true })
      .click();

    await expect(toast(adminPage, /dikembalikan ke saldo organizer/i)).toBeVisible();
  });
});

/** The payout account: required before any withdrawal, entered once (§5.7). */
async function addBankAccount(page: Page) {
  await page.getByLabel("Nama bank").fill("BCA");
  await page.getByLabel("Nomor rekening").fill("1234567890");
  await page.getByLabel("Nama pemilik rekening").fill("E2E Organizer");
  await page.getByRole("button", { name: /simpan/i }).click();
  await expect(toast(page, /rekening bank disimpan/i)).toBeVisible();
}

async function requestWithdrawal(page: Page, amount: number) {
  await page.getByRole("button", { name: /tarik dana/i }).click();
  await page.getByLabel("Jumlah penarikan").fill(String(amount));
  await page.getByRole("button", { name: /ajukan|tarik/i }).last().click();
  await expect(toast(page, /permintaan penarikan dikirim/i)).toBeVisible();
}

/**
 * The payout queue is platform-wide: it holds every organizer's requests,
 * including those of tests running in parallel. Scope to the smallest card that
 * carries *this* organization's name and the button we're after — filtering on
 * the name alone matches every ancestor div up to <body>.
 */
function payoutRow(page: Page, orgName: string, button: string) {
  return page
    .locator("div")
    .filter({ hasText: orgName })
    .filter({ has: page.getByRole("button", { name: button, exact: true }) })
    .last();
}
