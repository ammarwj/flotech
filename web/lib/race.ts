import type { RaceCategory, RacePeriod, SportDef, SportEvent } from "@/types/api";

/**
 * Fun run: a sport that holds no matches at all.
 *
 * Its own file rather than another export from `lib/scoring.ts`, and the line
 * between them is the point: that file answers "how is a match read" —
 * per set, per rubber, against a clock — while this one answers whether there
 * is a match to read. A fun run reaches none of those questions.
 */

/**
 * The single reader of `scoring === "none"`, the pattern of
 * `knockoutReadiness()` and `hasAdjustments()`.
 *
 * Every surface that hides schedule, standings, bracket or stats asks this one
 * function. A second copy would drift, and the drift is a dashboard that offers
 * a Jadwal tab for an event that can never have one.
 *
 * Note it reads `event.sport`, which is NOT a relation: the server derives it
 * from the catalog in `Event::sportDefinition()` and every resource publishes
 * it, so there is nothing to eager-load and no `plan.features`-style trap where
 * a forgotten `->with()` silently answers false.
 */
export function isRaceEvent(
  event: Pick<SportEvent, "sport"> | null | undefined
): boolean {
  return isRaceSport(event?.sport);
}

/**
 * The same question asked of a catalog entry rather than a saved event.
 *
 * The event form needs this: it is deciding what to render from the sport the
 * organizer has just picked in a dropdown, before any event exists to carry a
 * `sport` of its own. `isRaceEvent()` delegates here so the two can never part
 * — a form that renders the competition fields for a sport the dashboard has
 * already classified as a race is exactly the drift this file exists to stop.
 *
 * `undefined` answers **false**, the opposite of `hasFixtures()` and for the
 * same three-state reason: the catalog arrives a moment after the form mounts,
 * and a race-shaped form flashing over an unloaded sport would hide fields the
 * organizer is in the middle of filling in.
 */
export function isRaceSport(
  sport: Pick<SportDef, "scoring"> | null | undefined
): boolean {
  return sport?.scoring === "none";
}

/**
 * Whether this event runs on fixtures — the inverse, spelled out because it is
 * what most call sites actually mean.
 *
 * `undefined` answers **true**: an event that has not loaded yet must not have
 * its schedule hidden, the same three-state reasoning `planLimit()` documents.
 * So this is deliberately not `!isRaceEvent(event)` — that would read a
 * half-loaded page as a race and flicker the tabs away.
 */
export function hasFixtures(
  event: Pick<SportEvent, "sport"> | null | undefined
): boolean {
  if (!event?.sport) return true;
  return event.sport.scoring !== "none";
}

/**
 * The window in force right now, as the server decided it.
 *
 * Reads the `is_current` the API publishes rather than comparing the dates
 * here. A client that picked its own period would be a second reader of the
 * pricing rule — the `effective_payment_method` bug in another costume — and
 * the two would part across a boundary, showing early bird above a last-call
 * price.
 */
export function currentPeriod(
  periods: RacePeriod[] | null | undefined
): RacePeriod | null {
  return periods?.find((p) => p.is_current) ?? null;
}

/**
 * What this category costs right now, or null when it is not on sale.
 *
 * Null covers three situations the page says differently — no window open, no
 * price in the open window, nothing set up yet — so the caller decides the
 * words. Read off `current_price`, which the server resolved; never picked out
 * of `price_schedule` here, for the reason above.
 *
 * **Null is not zero.** Zero is a real price (a sponsored community run), so a
 * `?? 0` anywhere near this makes "free" and "not on sale" the same thing on
 * the one field the form reads to decide whether to show a button.
 */
export function currentPrice(
  category: Pick<RaceCategory, "current_price"> | null | undefined
): number | null {
  return category?.current_price ?? null;
}

/**
 * Whether a runner can enter this category at this moment.
 *
 * Both halves, because they fail independently: the category has to be open
 * (active, with room) and it has to have a price in the window that is running.
 * The server answers each separately — `is_open` and `current_price` — and
 * folding them server-side would lose which sentence to show.
 */
export function isEnterable(
  category: Pick<RaceCategory, "is_open" | "current_price"> | null | undefined
): boolean {
  return !!category?.is_open && currentPrice(category) !== null;
}

/**
 * Why this category cannot be entered, in the organizer's own words, or null
 * when it can.
 *
 * One answerer so the card, the form and the submit button cannot disagree
 * about which of the three doors is shut — the same reason
 * `knockoutReadiness()` exists rather than three components each deriving
 * "ready" from a pending count.
 */
export function blockedReason(
  category: Pick<RaceCategory, "is_open" | "is_active" | "remaining" | "current_price"> | null | undefined,
  hasOpenPeriod: boolean
): string | null {
  if (!category) return null;
  if (!category.is_active) return "Kategori ini sedang tidak dibuka.";
  if (category.remaining !== null && category.remaining <= 0) return "Kuota kategori ini sudah penuh.";
  // Checked after the category's own two doors: "the sale has not started" is
  // the event's state, and saying it over a category that is full would send
  // someone back to wait for a window that will not help them.
  if (!hasOpenPeriod) return "Pendaftaran sedang tidak dibuka.";
  if (currentPrice(category) === null) return "Kategori ini tidak dijual pada periode ini.";

  return null;
}

/**
 * Why this email cannot enter this category, or null when it can.
 *
 * The single answerer on the client, the pattern `blockedReason()` above set —
 * the card, the inline error under the email input and the submit button all
 * read this one function, so they cannot disagree about whether the address
 * qualifies.
 *
 * **Deliberately NOT folded into `blockedReason()`.** That one answers "can
 * this category be entered at all" from the category's own state, and it is
 * read to label the category card. Mixing the email in would make the card say
 * "cannot enter" before anyone has typed anything.
 *
 * An empty address answers **null** for the same reason: a form nobody has
 * filled in yet must not be scolded. The server refuses the empty case on its
 * own (`email` is required there).
 *
 * Matching mirrors RaceCategory::acceptsEmail() — exact, never by suffix. Two
 * matchers that differed would show a green form that 422s on submit.
 */
export function emailDomainBlocked(
  category: Pick<RaceCategory, "email_domains"> | null | undefined,
  email: string
): string | null {
  const domains = category?.email_domains ?? [];
  if (domains.length === 0) return null;

  const typed = email.trim();
  if (typed === "") return null;

  const at = typed.lastIndexOf("@");
  const domain = at === -1 ? "" : typed.slice(at + 1).replace(/\.+$/, "").toLowerCase();

  if (domain !== "" && domains.includes(domain)) return null;

  return `Kategori ini hanya untuk pendaftar dengan email ${allowedDomainList(domains)}.`;
}

/** The allowed domains as a runner reads them: "@ub.ac.id, @student.ub.ac.id". */
export function allowedDomainList(domains: string[]): string {
  return domains.map((d) => `@${d}`).join(", ");
}
