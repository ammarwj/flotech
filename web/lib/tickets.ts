import { dayChipLabel } from "@/lib/match-dates";
import type { TicketCategory, TicketDayMode } from "@/types/api";

/**
 * Every per-day branch the ticket UI makes, in one file.
 *
 * Mirrors the API's day rules the way `lib/plan.ts` mirrors `PlanGate` and
 * `lib/swiss.ts` mirrors `SwissService`: components ask these questions, they
 * never re-derive them from `day_mode` or from whether `days` is empty. The
 * price a buyer is shown and the price the server bills come from the same
 * formula here, so a per-day order cannot be quoted one number and charged
 * another.
 */

export const DAY_MODE_LABELS: Record<TicketDayMode, string> = {
  none: "Seluruh event",
  per_day: "Harian (pilih tanggal)",
  pass: "Terusan (semua hari)",
};

/**
 * What each mode does, phrased around the one thing that actually differs:
 * who picks the days, and how many times the price is charged.
 *
 * Both day-selling modes show the organizer the same date picker, so the copy
 * is what carries the difference — a hint that only said "pilih tanggal" left
 * the two modes looking identical on the form that configures them.
 */
export const DAY_MODE_HINTS: Record<TicketDayMode, string> = {
  none: "Satu tiket untuk seluruh event. Pembeli tidak memilih tanggal.",
  per_day:
    "Tanggal di bawah jadi pilihan pembeli. Dia bisa ambil satu hari saja, dan tiap hari dihitung satu harga tiket — 3 hari = 3× harga.",
  pass: "Tanggal di bawah jadi masa berlaku, bukan pilihan. Pembeli bayar satu harga untuk semua hari itu dan dapat satu QR per hari.",
};

/** The date picker's own label, which means different things per mode. */
export const DAY_FIELD_LABELS: Record<TicketDayMode, string> = {
  none: "Tanggal",
  per_day: "Tanggal yang dijual",
  pass: "Tanggal yang dicakup",
};

/**
 * What the price beside a category means, per mode.
 *
 * "/ hari" is the whole point of a per-day category: the number is one day's
 * price, not the trip's.
 */
export function priceUnitLabel(mode: TicketDayMode): string | null {
  return mode === "per_day" ? "/ hari" : null;
}

export function dayModeLabel(mode: TicketDayMode): string {
  return DAY_MODE_LABELS[mode] ?? DAY_MODE_LABELS.none;
}

/** Whether this category sells by the day at all. */
export function usesDays(category: TicketCategory): boolean {
  return category.day_mode !== "none";
}

/** The dates this category sells, earliest first. */
export function sellingDates(category: TicketCategory): string[] {
  return (category.days ?? []).map((day) => day.event_date);
}

/**
 * How many ticket prices one seat costs for the given dates.
 *
 * The whole per-day/pass difference, and the mirror of
 * `TicketCategory::pricedDays()`: a per-day buyer pays once per date picked, a
 * pass buyer pays once for all of them.
 */
export function pricedDays(mode: TicketDayMode, dates: string[]): number {
  return mode === "per_day" ? Math.max(1, dates.length) : 1;
}

/**
 * Which dates an order covers, given what the buyer picked.
 *
 * A pass covers every date it sells whatever the buyer touched, which is why
 * the pass panel is read-only — mirrors the controller's `datesFor()`.
 */
export function coveredDates(
  category: TicketCategory,
  picked: string[],
): string[] {
  if (category.day_mode === "pass") return sellingDates(category);
  if (category.day_mode !== "per_day") return [];

  return [...picked].sort();
}

/** Seats still free on one date, or null when unlimited. */
export function remainingOn(
  category: TicketCategory,
  date: string,
): number | null {
  const day = (category.days ?? []).find((d) => d.event_date === date);

  return day ? day.remaining : null;
}

/**
 * Whether this category can be bought at all right now.
 *
 * A day-selling category whose every date is full is sold out even though its
 * own `remaining` says otherwise — that counter answers the category's
 * question, not the venue's. A category with no dates can sell nothing.
 */
export function isSoldOut(category: TicketCategory): boolean {
  if (!usesDays(category)) {
    return category.remaining !== null && category.remaining <= 0;
  }

  const dates = sellingDates(category);
  if (dates.length === 0) return true;

  return dates.every((date) => {
    const left = remainingOn(category, date);

    return left !== null && left <= 0;
  });
}

/**
 * The dates a buyer may still pick, with why a chip is disabled.
 *
 * The remaining count travels with it so the chip can say so: a buyer who
 * learns "sisa 1" before clicking does not have to discover it through a 422.
 */
export function selectableDates(
  category: TicketCategory,
  seats: number,
): { date: string; remaining: number | null; disabled: boolean }[] {
  return (category.days ?? []).map((day) => ({
    date: day.event_date,
    remaining: day.remaining,
    disabled: day.remaining !== null && day.remaining < seats,
  }));
}

/**
 * A range of days as one line: "14 – 16 Nov" for a run, chips joined otherwise.
 *
 * Dates are expected sorted. Gaps matter to a buyer who picked Friday and
 * Sunday, so a non-contiguous pick is never flattened into a range.
 */
export function dateRangeLabel(dates: string[]): string {
  if (dates.length === 0) return "—";
  if (dates.length === 1) return dayChipLabel(dates[0]);

  const contiguous = dates.every((date, i) => {
    if (i === 0) return true;
    const previous = new Date(`${dates[i - 1]}T12:00:00Z`);
    previous.setUTCDate(previous.getUTCDate() + 1);

    return previous.toISOString().slice(0, 10) === date;
  });

  return contiguous
    ? `${dayChipLabel(dates[0])} – ${dayChipLabel(dates[dates.length - 1])}`
    : dates.map(dayChipLabel).join(", ");
}

/** "2 orang × 3 hari", or just the seats when days do not multiply the price. */
export function unitBreakdownLabel(
  mode: TicketDayMode,
  seats: number,
  dates: string[],
): string {
  const days = pricedDays(mode, dates);

  return days > 1 ? `${seats} orang × ${days} hari` : `${seats} orang`;
}

/**
 * "6 tiket" — the paid units a basket comes to, spelled as a count.
 *
 * Kept beside unitBreakdownLabel() rather than inlined: the two halves of the
 * same sentence ("2 orang × 3 hari" = "6 tiket") would otherwise be worded in
 * one place and counted in another.
 */
export function unitCountLabel(
  mode: TicketDayMode,
  seats: number,
  dates: string[],
): string {
  return `${seats * pricedDays(mode, dates)} tiket`;
}
