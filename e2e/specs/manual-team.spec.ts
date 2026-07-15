import { unique } from "../fixtures/api";
import { expect, signIn, test, toast } from "../fixtures/test";

/**
 * Offline registration — teams that signed up over WhatsApp, on paper, or paid
 * the organizer in cash. They never touch the public form, so the organizer
 * types them in; a team that isn't in the system can't be drawn into a schedule.
 */
test.describe("Tim manual (pendaftaran di luar aplikasi)", () => {
  test("organizer menambah tim beserta pemain & posisinya", async ({ page, api, organizer }) => {
    const event = await api.liveEvent(organizer.account.token, organizer.org.id);
    const teamName = unique("Tim Kampung");

    await signIn(page, organizer.account.email);
    await page.goto(`/organizer/events/${event.id}/registrations`);

    await page.getByRole("button", { name: "Tambah Tim" }).click();
    await page.getByLabel("Nama tim").fill(teamName);
    await page.getByLabel("Nama kontak").fill("Pak RT");
    await page.getByLabel("No. HP kontak").fill("081200000000");
    await page.getByLabel("Nama pemain 1").fill("Joko");
    await page.getByLabel("Nomor punggung pemain 1").fill("1");
    // Positions come from the sport's master (sport_positions), so this is a
    // dropdown of futsal's positions — not a box you can type anything into.
    await page.getByLabel("Posisi pemain 1").selectOption({ label: "Kiper" });
    await page.getByRole("button", { name: "Tambah tim", exact: true }).click();

    await expect(toast(page, /tim berhasil ditambahkan/i)).toBeVisible();

    // The organizer entering it *is* the verification: no approval queue.
    await expect(page.getByText(teamName)).toBeVisible();
    await expect(page.getByText("Disetujui").first()).toBeVisible();
  });

  test("tim manual bisa diperbaiki rosternya kemudian", async ({ page, api, organizer }) => {
    const event = await api.liveEvent(organizer.account.token, organizer.org.id);
    const team = await api.addTeamManually(
      organizer.account.token,
      organizer.org.id,
      event,
      unique("Tim Typo"),
    );

    await signIn(page, organizer.account.email);
    await page.goto(`/organizer/events/${event.id}/registrations`);

    // There is no participant account behind an offline team, so the organizer
    // is the only one who can fix a name or add the player who turned up late.
    await page.getByRole("button", { name: `Ubah tim ${team.name}` }).click();
    await page.getByRole("button", { name: "Pemain", exact: true }).click();
    await page.getByLabel("Nama pemain 2").fill("Pemain Susulan");
    await page.getByLabel("Posisi pemain 2").selectOption({ label: "Flank" });
    await page.getByRole("button", { name: /simpan perubahan/i }).click();

    await expect(toast(page, /data tim diperbarui/i)).toBeVisible();

    // The roster stores the key ('flank'); the card must show the master's label.
    await page.getByText(team.name, { exact: true }).click();
    await expect(page.getByText("Flank")).toBeVisible();
  });

  test("uang tim manual tidak masuk dompet — dibayar di luar platform", async ({
    page,
    api,
    organizer,
  }) => {
    const event = await api.liveEvent(organizer.account.token, organizer.org.id, {
      registration_fee: 150_000,
    });
    await api.addTeamManually(organizer.account.token, organizer.org.id, event);

    await signIn(page, organizer.account.email);
    await page.goto("/organizer/wallet");

    // Crediting the wallet here would let the organizer withdraw money the
    // platform never received.
    await expect(page.getByText("Belum ada mutasi")).toBeVisible();
  });
});
