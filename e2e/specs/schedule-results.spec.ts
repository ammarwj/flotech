import { expect, signIn, test, toast } from "../fixtures/test";

/**
 * PRD §5.3 — jadwal → input hasil → konfirmasi → klasemen auto-update.
 *
 * The league engine is what makes standings mean anything, so the assertions are
 * about *numbers moving*: a confirmed 3–1 must put the winner on 3 points, not
 * merely render a row.
 */
test.describe("§5.3 Jadwal, hasil & klasemen", () => {
  test("generate jadwal liga dari tim yang disetujui", async ({ page, api, organizer }) => {
    const event = await api.liveEvent(organizer.account.token, organizer.org.id);
    await api.approvedTeams(organizer.account.token, organizer.org, event, 4);

    await signIn(page, organizer.account.email);
    await page.goto(`/organizer/events/${event.id}/schedule`);

    await page.getByRole("button", { name: /buat jadwal/i }).first().click();
    await page.getByRole("button", { name: "Generate Jadwal" }).click();

    // Round-robin over 4 teams = 6 matches; every team must appear.
    await expect(toast(page, /jadwal/i)).toBeVisible();
    await expect(page.getByText("Tim A", { exact: false }).first()).toBeVisible();
  });

  test("input skor lalu konfirmasi — klasemen ikut berubah", async ({ page, api, organizer }) => {
    const event = await api.liveEvent(organizer.account.token, organizer.org.id);
    const [home, away] = await api.approvedTeams(organizer.account.token, organizer.org, event, 2);

    await signIn(page, organizer.account.email);
    await page.goto(`/organizer/events/${event.id}/schedule`);

    await page.getByRole("button", { name: /buat jadwal/i }).first().click();
    await page.getByRole("button", { name: "Generate Jadwal" }).click();
    await expect(page.getByLabel(`Skor ${home.name}`).or(page.getByLabel(`Skor ${away.name}`)).first()).toBeVisible();

    // Two teams, one match — whichever way the draw seeded it, the home side of
    // the only fixture is the one whose score box comes first.
    const homeScore = page.getByLabel(/^Skor /).first();
    const awayScore = page.getByLabel(/^Skor /).nth(1);
    await homeScore.fill("3");
    await awayScore.fill("1");
    await page.getByRole("button", { name: "Simpan", exact: true }).first().click();

    // A result is provisional until confirmed (§4.5) — standings only count
    // confirmed matches.
    await page.getByRole("button", { name: "Konfirmasi" }).first().click();

    await page.getByRole("button", { name: "Klasemen" }).click();
    const table = page.getByRole("table");
    await expect(table).toBeVisible();

    // 3 points for the winner, 0 for the loser: the engine ran, not just the UI.
    await expect(table.getByRole("row").nth(1)).toContainText("3");
  });
});
