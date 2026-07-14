import { unique } from "../fixtures/api";
import { expect, signIn, test, toast } from "../fixtures/test";

/**
 * PRD §5.2 — Peserta: buka landing page → daftar tim → menunggu approval →
 * organizer approve/reject → tim terlihat di dashboard peserta.
 *
 * The event itself is arranged over the API: what's under test is the public
 * registration form and the organizer's approval screen, not event creation
 * (that's §5.1's job).
 */
test.describe("§5.2 Peserta — daftar tim", () => {
  test("peserta mendaftarkan tim dari landing page publik", async ({ page, api, organizer }) => {
    const event = await api.liveEvent(organizer.account.token, organizer.org.id);
    const participant = await api.registerUser("peserta");
    await signIn(page, participant.email);

    const teamName = unique("Tim Garuda");

    await page.goto(`/${organizer.org.slug}/${event.slug}/register`);
    await page.getByLabel("Nama tim").fill(teamName);
    await page.getByLabel("Kota").fill("Bandung");
    await page.getByLabel("Nama kontak").fill("Budi");
    await page.getByLabel("No. HP kontak").fill("081234567890");
    await page.getByPlaceholder("Nama pemain").first().fill("Pemain Satu");
    await page.getByRole("button", { name: "Kirim Pendaftaran" }).click();

    await expect(page.getByRole("heading", { name: /pendaftaran terkirim/i })).toBeVisible();

    // A team is not in the tournament until the organizer says so (§4.2).
    await page.goto("/participant");
    await expect(page.getByText(teamName)).toBeVisible();
    await expect(page.getByText("Menunggu", { exact: false }).first()).toBeVisible();
  });

  test("organizer menyetujui tim, statusnya berubah di dashboard peserta", async ({
    page,
    api,
    organizer,
  }) => {
    const event = await api.liveEvent(organizer.account.token, organizer.org.id);
    const participant = await api.registerUser("peserta");
    const teamName = unique("Tim Rajawali");
    await api.registerTeam(organizer.org.slug, event.slug, teamName, participant.token);

    await signIn(page, organizer.account.email);
    await page.goto(`/organizer/events/${event.id}/registrations`);

    await expect(page.getByText(teamName)).toBeVisible();
    await page.getByRole("button", { name: "Setujui", exact: true }).click();
    await expect(toast(page, /tim berhasil disetujui/i)).toBeVisible();

    // The participant sees the decision from their own side of the app.
    await signIn(page, participant.email);
    await page.goto("/participant");
    await expect(page.getByText(teamName)).toBeVisible();
    await expect(page.getByText("Disetujui").first()).toBeVisible();
  });

  test("organizer menolak tim", async ({ page, api, organizer }) => {
    const event = await api.liveEvent(organizer.account.token, organizer.org.id);
    const teamName = unique("Tim Ditolak");
    await api.registerTeam(organizer.org.slug, event.slug, teamName);

    await signIn(page, organizer.account.email);
    await page.goto(`/organizer/events/${event.id}/registrations`);

    await expect(page.getByText(teamName)).toBeVisible();
    await page.getByRole("button", { name: "Tolak", exact: true }).click();
    await expect(toast(page, /tim berhasil ditolak/i)).toBeVisible();
  });

  test("pendaftaran ditutup saat kuota tim penuh", async ({ page, api, organizer }) => {
    // max_teams=2, both taken: the form must refuse rather than overfill (§4.2).
    const event = await api.liveEvent(organizer.account.token, organizer.org.id, { max_teams: 2 });
    await api.registerTeam(organizer.org.slug, event.slug);
    await api.registerTeam(organizer.org.slug, event.slug);

    await page.goto(`/${organizer.org.slug}/${event.slug}/register`);
    await page.getByLabel("Nama tim").fill(unique("Tim Kelebihan"));
    await page.getByLabel("Nama kontak").fill("Budi");
    await page.getByLabel("No. HP kontak").fill("081234567890");
    await page.getByPlaceholder("Nama pemain").first().fill("Pemain Satu");
    await page.getByRole("button", { name: "Kirim Pendaftaran" }).click();

    await expect(page.getByText(/kuota tim untuk event ini sudah penuh/i)).toBeVisible();
  });
});
