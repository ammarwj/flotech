import { PASSWORD, unique } from "../fixtures/api";
import { expect, signIn, test, toast } from "../fixtures/test";
import { daysAhead, pickDate } from "../fixtures/ui";

/**
 * PRD §5.1 — Organizer: daftar akun → onboarding → buat & publish event.
 * Plus the password-reset entry point, which is part of getting *into* the app.
 */
test.describe("§5.1 Organizer — onboarding & buat event", () => {
  test("daftar akun baru lalu diarahkan ke onboarding", async ({ page }) => {
    const email = `${unique("daftar")}@e2e.test`;

    await page.goto("/register");
    await page.getByLabel("Nama lengkap").fill("Organizer Baru");
    await page.getByLabel("Email").fill(email);
    await page.getByLabel("Password", { exact: true }).fill(PASSWORD);
    await page.getByLabel("Konfirmasi password").fill(PASSWORD);
    await page.getByRole("button", { name: "Daftar" }).click();

    // A user with no organization has nothing to see in the dashboard, so the
    // app must land them in onboarding rather than an empty organizer shell.
    await expect(page).toHaveURL(/\/onboarding/);
    await expect(page.getByRole("heading", { name: "Buat organisasi" })).toBeVisible();
  });

  test("password lemah ditolak sebelum request dikirim", async ({ page }) => {
    await page.goto("/register");
    await page.getByLabel("Nama lengkap").fill("Organizer Baru");
    await page.getByLabel("Email").fill(`${unique("lemah")}@e2e.test`);
    // Backend requires letters AND numbers; the form must say so itself.
    await page.getByLabel("Password", { exact: true }).fill("tanpaangka");
    await page.getByLabel("Konfirmasi password").fill("tanpaangka");
    await page.getByRole("button", { name: "Daftar" }).click();

    await expect(page.getByText("Harus mengandung minimal satu angka")).toBeVisible();
    await expect(page).toHaveURL(/\/register/);
  });

  test("login mengantar organizer yang sudah punya organisasi ke dashboard", async ({ page, organizer }) => {
    await page.goto("/login");
    await page.getByLabel("Email").fill(organizer.account.email);
    await page.getByLabel("Password").fill(PASSWORD);
    await page.getByRole("button", { name: "Masuk" }).click();

    await expect(page).toHaveURL(/\/organizer$/);
    await expect(page.getByRole("heading", { name: /selamat datang/i })).toBeVisible();
  });

  test("lupa password bisa dicapai dari halaman login", async ({ page, organizer }) => {
    await page.goto("/login");
    await page.getByRole("link", { name: "Lupa password?" }).click();

    await expect(page).toHaveURL(/\/forgot-password/);
    await page.getByLabel("Email").fill(organizer.account.email);
    await page.getByRole("button", { name: "Kirim tautan reset" }).click();

    // Deliberately non-committal: the endpoint never reveals whether an email is
    // registered, so the UI must not either.
    await expect(page.getByText(/tautan reset telah dikirim/i)).toBeVisible();
  });

  test("onboarding membuat organisasi lalu masuk ke dashboard", async ({ page, api }) => {
    const account = await api.registerUser("onboard");
    await signIn(page, account.email);

    await page.goto("/onboarding");
    await page.getByLabel("Nama organisasi").fill(unique("Sports EO"));
    await page.getByRole("button", { name: /lanjut|simpan|buat/i }).first().click();

    await expect(toast(page, /organisasi berhasil dibuat/i)).toBeVisible();
  });

  test("buat event lalu publish — landing page publiknya hidup", async ({ page, organizer, api }) => {
    await signIn(page, organizer.account.email);

    const name = unique("Liga E2E");
    await page.goto("/organizer/events/new");
    await page.getByLabel("Nama event").fill(name);
    await page.getByLabel("Cabang olahraga").selectOption("futsal");
    await page.getByLabel("Format", { exact: true }).selectOption("league");
    await pickDate(page, "Tanggal mulai", daysAhead(10));
    await pickDate(page, "Tanggal selesai", daysAhead(11));
    await page.getByRole("button", { name: /simpan|buat event/i }).click();

    await expect(toast(page, /event berhasil dibuat/i)).toBeVisible();

    // Publishing is what turns a draft into the public landing page of §4.1.
    await page.goto("/organizer/events");
    await expect(page.getByText(name)).toBeVisible();
  });
});
