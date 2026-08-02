import { expect, signIn, test, toast } from "../fixtures/test";

/**
 * Naikkan paket, in a browser.
 *
 * The rules — what counts as an upgrade, what the difference costs, which order
 * stops being a credit — are settled by 13 backend tests. What only a browser
 * can answer is whether the two buttons that raise the bill exist where an
 * organizer would look for them, and whether the page tells the truth afterwards.
 *
 * The gateway stays on, so the bill is a Snap transaction the spec settles the
 * same way grantCredit() does: by posting the notification Midtrans would.
 */
test.describe("Naikkan paket", () => {
  test("kredit yang belum dipakai bisa dinaikkan dari halaman Pembelian Paket", async ({
    page,
    api,
  }) => {
    const account = await api.registerUser("upgrade");
    const org = await api.createOrg(account.token);
    await api.grantCredit(account.token, org.id, "starter");

    await signIn(page, account.email);
    await page.goto("/organizer/billing");

    await expect(page.getByRole("heading", { name: "Paket siap dipakai" })).toBeVisible();
    await page.getByRole("button", { name: "Naikkan paket" }).first().click();

    // Only plans above the current one, and priced as the difference. Pro is
    // 350.000 against a Starter bought for 150.000.
    const dialog = page.getByRole("dialog");
    // exact: "Naik ke Pro" is a prefix of "Naik ke Professional", and both are
    // offered here.
    await expect(dialog.getByRole("button", { name: "Naik ke Pro", exact: true })).toBeVisible();
    await expect(dialog.getByText("Rp 200.000")).toBeVisible();

    // Downgrade is not a disabled control, it is an absent one — the server
    // never offers a plan that grants less.
    await expect(dialog.getByRole("button", { name: /Naik ke Starter/ })).toHaveCount(0);

    await dialog.getByRole("button", { name: "Naik ke Pro", exact: true }).click();

    // Gateway is on, so this lands on Midtrans; the bill is what we came for.
    await expect(async () => {
      const orders = await api.planOrders(account.token, org.id);
      expect(orders.some((o) => o.upgrade_of_id !== null && o.amount === 200000)).toBe(true);
    }).toPass({ timeout: 15_000 });

    const orders = await api.planOrders(account.token, org.id);
    const upgrade = orders.find((o) => o.upgrade_of_id !== null)!;
    await api.settleOrder(upgrade.id, org.id, account.token);

    await page.goto("/organizer/billing");

    // One credit, not two. The Starter that was paid for is retired, and the
    // count is the assertion — "a Pro row appeared" would pass either way.
    // Scoped to the section: an identical "Buat event" link lives in the banner
    // above it. And it is a link, not a button — the card renders Button asChild.
    const credits = page.locator("section", {
      has: page.getByRole("heading", { name: "Paket siap dipakai" }),
    });
    await expect(credits.getByRole("link", { name: "Buat event" })).toHaveCount(1);
    await expect(credits.getByText("Pro", { exact: true })).toBeVisible();
  });

  test("event yang sudah jalan bisa dinaikkan dari halaman edit event", async ({
    page,
    organizer,
    api,
  }) => {
    const event = await api.createEvent(organizer.account.token, organizer.org.id);

    await signIn(page, organizer.account.email);
    await page.goto(`/organizer/events/${event.id}/edit`);

    // The panel names the plan the event runs on — the question an organizer
    // arrives with — and the way up sits on it.
    await expect(page.getByText("Paket Pro")).toBeVisible();
    await page.getByRole("button", { name: "Naikkan paket" }).click();

    const dialog = page.getByRole("dialog");
    await expect(dialog.getByRole("button", { name: "Naik ke Professional" })).toBeVisible();
    await dialog.getByRole("button", { name: "Naik ke Professional" }).click();

    await expect(async () => {
      const orders = await api.planOrders(organizer.account.token, organizer.org.id);
      expect(orders.some((o) => o.upgrade_of_id !== null)).toBe(true);
    }).toPass({ timeout: 15_000 });

    const orders = await api.planOrders(organizer.account.token, organizer.org.id);
    const upgrade = orders.find((o) => o.upgrade_of_id !== null)!;
    await api.settleOrder(upgrade.id, organizer.org.id, organizer.account.token);

    // The click handed the browser to Midtrans, so there is nothing here to
    // reload — come back by URL.
    await page.goto(`/organizer/events/${event.id}/edit`);

    // The event moved, and it still has exactly one order against it.
    await expect(page.getByText("Paket Professional")).toBeVisible();
    const after = await api.planOrders(organizer.account.token, organizer.org.id);
    expect(after.filter((o) => o.event_id === event.id)).toHaveLength(1);
  });

  test("paket teratas tidak menawarkan apa-apa, dan mengatakannya", async ({ page, api }) => {
    const account = await api.registerUser("teratas");
    const org = await api.createOrg(account.token);
    await api.grantCredit(account.token, org.id, "professional");

    await signIn(page, account.email);
    await page.goto("/organizer/billing");
    await page.getByRole("button", { name: "Naikkan paket" }).first().click();

    // An empty box would read as a loading failure. Nothing above Professional
    // is a fact, so the dialog says so.
    await expect(
      page.getByRole("dialog").getByText("Tidak ada paket yang lebih tinggi"),
    ).toBeVisible();
  });
});
