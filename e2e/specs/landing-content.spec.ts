import { unique } from "../fixtures/api";
import { expect, test } from "../fixtures/test";

/**
 * FAQ on the landing page is content, not code: a super admin edits it at
 * /admin/faqs and the section reads it back from the API. Nothing is hardcoded
 * in the component, so "did my edit reach the landing page?" is a real question
 * with a real answer — and the only way to ask it is end to end.
 *
 * Unlike the orgs and events this suite leaves lying around, a stray FAQ is
 * visible to anyone who opens the dev landing page. Hence the cleanup.
 */
test.describe("Konten landing — FAQ", () => {
  const answer = "Bisa, kapan saja lewat halaman Upgrade.";

  /**
   * A marker of this test's own, swept afterwards whether it passed or not.
   * Per-test rather than per-file: two tests sharing one marker would only be
   * safe as long as Playwright never ran them at the same time, and that is a
   * scheduling detail, not a guarantee.
   */
  const faq = test.extend<{ marker: string }>({
    marker: async ({ api }, use) => {
      const marker = unique("E2E FAQ");
      await use(marker);
      await api.purgeFaqs(await api.loginAsSuperAdmin(), marker);
    },
  });

  faq("FAQ yang ditambah admin muncul di landing page", async ({ adminPage: page, marker }) => {
    const question = `${marker} — apakah paket bisa diubah?`;
    await page.goto("/admin/faqs");

    await page.getByLabel("Pertanyaan").fill(question);
    await page.getByLabel("Jawaban").fill(answer);
    await page.getByRole("button", { name: "Tambah FAQ" }).click();

    await expect(page.getByText(question)).toBeVisible();

    // The section is fetched client-side, so it is absent from the SSR HTML and
    // only appears after hydration — assert on the rendered page, not the source.
    await page.goto("/");
    const item = page.getByRole("button", { name: new RegExp(marker) });
    await expect(item).toBeVisible();

    // The answer is collapsed until its question is opened.
    await item.click();
    await expect(page.getByText(answer)).toBeVisible();
  });

  faq("FAQ nonaktif tidak ikut tampil di landing page", async ({ adminPage: page, marker }) => {
    const question = `${marker} — apakah paket bisa diubah?`;
    await page.goto("/admin/faqs");

    await page.getByLabel("Pertanyaan").fill(question);
    await page.getByLabel("Jawaban").fill(answer);
    // `is_active` is what separates the public endpoint from the admin one: both
    // read the same table, and only the admin sees what's switched off.
    await page.getByLabel("Tampilkan di landing page").uncheck();
    await page.getByRole("button", { name: "Tambah FAQ" }).click();

    await expect(page.getByText("Nonaktif")).toBeVisible();

    await page.goto("/");
    await expect(page.getByRole("button", { name: new RegExp(marker) })).toHaveCount(0);
  });
});
