import { unique } from "../fixtures/api";
import { expect, test } from "../fixtures/test";

/**
 * FAQ and testimonials on the landing page are content, not code: a super admin
 * edits them at /admin/faqs and /admin/testimonials, and the sections read them
 * back from the API. Nothing is hardcoded in the components, so "did my edit
 * reach the landing page?" is a real question — and the only way to ask it is
 * end to end.
 *
 * Unlike the orgs and events this suite leaves lying around, a stray FAQ or
 * testimonial is visible to anyone who opens the dev landing page. Hence the
 * cleanup, which runs whether the test passed or not.
 *
 * A marker is per-test rather than per-file: two tests sharing one would only be
 * safe as long as Playwright never ran them at the same time, and that is a
 * scheduling detail, not a guarantee.
 */
const landing = test.extend<{ marker: string }>({
  marker: async ({ api }, use) => {
    const marker = unique("E2E");
    await use(marker);

    const token = await api.loginAsSuperAdmin();
    await api.purgeLandingContent(token, "faqs", "question", marker);
    await api.purgeLandingContent(token, "testimonials", "name", marker);
  },
});

landing.describe("Konten landing — FAQ", () => {
  const answer = "Bisa, kapan saja lewat halaman Upgrade.";

  landing("FAQ yang ditambah admin muncul di landing page", async ({ adminPage: page, marker }) => {
    await page.goto("/admin/faqs");

    await page.getByLabel("Pertanyaan").fill(`${marker} — apakah paket bisa diubah?`);
    await page.getByLabel("Jawaban").fill(answer);
    await page.getByRole("button", { name: "Tambah FAQ" }).click();

    await expect(page.getByText(marker)).toBeVisible();

    // The section is fetched client-side, so it is absent from the SSR HTML and
    // only appears after hydration — assert on the rendered page, not the source.
    await page.goto("/");
    const item = page.getByRole("button", { name: new RegExp(marker) });
    await expect(item).toBeVisible();

    // The answer is collapsed until its question is opened.
    await item.click();
    await expect(page.getByText(answer)).toBeVisible();
  });

  landing("FAQ nonaktif tidak ikut tampil di landing page", async ({ adminPage: page, marker }) => {
    await page.goto("/admin/faqs");

    await page.getByLabel("Pertanyaan").fill(`${marker} — apakah paket bisa diubah?`);
    await page.getByLabel("Jawaban").fill(answer);
    // `is_active` is what separates the public endpoint from the admin one: both
    // read the same table, and only the admin sees what's switched off.
    await page.getByLabel("Tampilkan di landing page").uncheck();
    await page.getByRole("button", { name: "Tambah FAQ" }).click();

    await expect(page.getByText("Nonaktif", { exact: true })).toBeVisible();

    await page.goto("/");
    await expect(page.getByRole("button", { name: new RegExp(marker) })).toHaveCount(0);
  });
});

landing.describe("Konten landing — testimoni", () => {
  const quote = "Turnamen 32 tim beres tanpa spreadsheet sama sekali.";

  async function fill(page: import("@playwright/test").Page, marker: string) {
    await page.getByLabel("Nama").fill(`${marker} Rizky`);
    await page.getByLabel("Peran").fill("Ketua Liga Futsal Bandung");
    await page.getByLabel("Inisial").fill("RP");
    await page.getByLabel("Kutipan").fill(quote);
  }

  landing("testimoni yang ditambah admin muncul di section Kata Mereka", async ({
    adminPage: page,
    marker,
  }) => {
    await page.goto("/admin/testimonials");

    await fill(page, marker);
    await page.getByLabel("Warna avatar").selectOption({ label: "Ungu" });
    await page.getByLabel("Rating (bintang)").fill("4");
    await page.getByRole("button", { name: "Tambah testimoni" }).click();

    await expect(page.getByText(`${marker} Rizky`)).toBeVisible();

    await page.goto("/");

    // Scoped to this testimonial's own card, not the whole page: the seeded
    // testimonials share these roles, and asserting each field separately would
    // pass even if they'd been scattered across different cards.
    const card = page.locator(".tcard").filter({ hasText: `${marker} Rizky` });
    await expect(card).toBeVisible();

    // Substring match: the card wraps the quote in typographic quotes
    // (&ldquo;/&rdquo;), which are the component's presentation, not the content.
    await expect(card.getByText(quote)).toBeVisible();
    await expect(card.getByText("Ketua Liga Futsal Bandung")).toBeVisible();

    // The avatar is initials on a CSS gradient, never an uploaded image — the DB
    // stores only the preset key (see AVATAR_PRESETS in web/lib/landing.ts).
    await expect(card.getByText("RP")).toBeVisible();

    // Rating is rendered as that many stars, not as the number 4.
    await expect(card.locator(".tstars svg")).toHaveCount(4);
  });

  landing("testimoni nonaktif tidak ikut tampil di landing page", async ({
    adminPage: page,
    marker,
  }) => {
    await page.goto("/admin/testimonials");

    await fill(page, marker);
    await page.getByLabel("Tampilkan di landing page").uncheck();
    await page.getByRole("button", { name: "Tambah testimoni" }).click();

    await expect(page.getByText("Nonaktif", { exact: true })).toBeVisible();

    await page.goto("/");
    await expect(page.getByText(`${marker} Rizky`)).toHaveCount(0);
  });
});
