import { expect, type Page } from "@playwright/test";

/**
 * The date field is a custom calendar (components/ui/date-picker.tsx), not an
 * `<input type="date">` — it can't be filled, only clicked. Open it, walk to the
 * right month, then click the day by its accessible name ("15 Juli 2026").
 */
export async function pickDate(page: Page, fieldLabel: string, date: Date): Promise<void> {
  await page.getByLabel(fieldLabel).click();

  const calendar = page.getByRole("dialog");
  await expect(calendar).toBeVisible();

  const dayName = date.toLocaleDateString("id-ID", { day: "numeric", month: "long", year: "numeric" });
  const day = calendar.getByRole("button", { name: dayName, exact: true });

  // The picker opens on today's month; the target may be one or two months out.
  for (let i = 0; i < 12 && !(await day.isVisible()); i++) {
    await calendar.getByRole("button", { name: "Bulan berikutnya" }).click();
  }

  await day.click();
  await expect(calendar).toBeHidden();
}

export function daysAhead(days: number): Date {
  const d = new Date();
  d.setDate(d.getDate() + days);
  return d;
}
