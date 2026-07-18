import { TZDate } from "@date-fns/tz";

import type { Match } from "@/types/api";

export type DateGroup = { key: string; iso: string | null; list: Match[] };

/**
 * Every helper here takes the event's timezone explicitly rather than falling
 * back to the browser's. A fixture happens at a venue, so its kickoff time and
 * its matchday are facts about that venue's clock — a viewer in Makassar must
 * read the same "15:00 WIB" as one in Jakarta, and must not see the fixture
 * slide onto the previous day.
 *
 * The tz is a required parameter (never a default) so a call site that forgets
 * it fails to compile instead of silently reverting to the old bug.
 */

/**
 * Zones offered when creating an event. Indonesia's three are the common case;
 * the backend accepts any IANA identifier, so this list can grow without a
 * schema change if the platform starts serving events abroad.
 */
export const TIMEZONES: { value: string; label: string }[] = [
  { value: "Asia/Jakarta", label: "WIB — Waktu Indonesia Barat (GMT+7)" },
  { value: "Asia/Makassar", label: "WITA — Waktu Indonesia Tengah (GMT+8)" },
  { value: "Asia/Jayapura", label: "WIT — Waktu Indonesia Timur (GMT+9)" },
];

/**
 * Kickoff time in the event's zone, "HH:mm".
 *
 * Formatted as en-GB, not id-ID: Indonesian locale renders times with a dot
 * ("15.00"), and the scoreboard reads as a colon everywhere else in the UI.
 */
export function timeOf(iso: string | null, tz: string): string | null {
  if (!iso) return null;
  return new Intl.DateTimeFormat("en-GB", {
    timeZone: tz,
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  }).format(new Date(iso));
}

/**
 * The zone's short name as people say it: "WIB" / "WITA" / "WIT", falling back
 * to "GMT+X" elsewhere. Derived from the zone, never hardcoded — the old UI
 * printed a literal "WIB" next to a time formatted in the viewer's zone.
 */
export function tzLabel(tz: string): string {
  const parts = new Intl.DateTimeFormat("id-ID", {
    timeZone: tz,
    timeZoneName: "short",
  }).formatToParts(new Date());

  return parts.find((p) => p.type === "timeZoneName")?.value ?? "";
}

/** Stable per-day key in the event's zone; undated matches share the "tbd" bucket. */
export function dateKeyOf(iso: string | null, tz: string): string {
  if (!iso) return "tbd";
  // en-CA gives "YYYY-MM-DD", which sorts and compares as a plain string.
  return new Intl.DateTimeFormat("en-CA", {
    timeZone: tz,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(new Date(iso));
}

export function fullDateLabel(iso: string | null, tz: string): string {
  if (!iso) return "Jadwal menyusul";
  return new Date(iso).toLocaleDateString("id-ID", {
    timeZone: tz,
    weekday: "long",
    day: "numeric",
    month: "long",
    year: "numeric",
  });
}

export function tabDateLabel(iso: string | null, tz: string): string {
  if (!iso) return "TBD";
  return new Date(iso).toLocaleDateString("id-ID", {
    timeZone: tz,
    weekday: "short",
    day: "numeric",
    month: "short",
  });
}

/** ISO instant → value for <input type="datetime-local">, read in the event's zone. */
export function toEventInput(iso: string | null, tz: string): string {
  if (!iso) return "";
  const d = new TZDate(new Date(iso), tz);
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

/**
 * Value from <input type="datetime-local"> → ISO instant, interpreting what the
 * organizer typed as the venue's wall clock. `new Date(value)` would read it as
 * the organizer's own zone instead, which is only right when they happen to sit
 * in the same one as the venue.
 */
export function fromEventInput(value: string, tz: string): string | null {
  if (!value) return null;
  const [date, time] = value.split("T");
  const [y, mo, d] = date.split("-").map(Number);
  const [h, mi] = time.split(":").map(Number);
  return new TZDate(y, mo - 1, d, h, mi, 0, 0, tz).toISOString();
}

/**
 * The matchday to land on when nothing is picked yet: today, else the next day
 * still to come, else the last day played (an event that is already over opens
 * on its final matchday). "Today" is today at the venue. Undated fixtures are
 * never the default.
 */
export function defaultDateKey(groups: DateGroup[], tz: string): string | undefined {
  const dated = groups.filter((g) => g.iso);
  if (dated.length === 0) return groups[0]?.key;

  const today = dateKeyOf(new Date().toISOString(), tz);
  const todayGroup = dated.find((g) => g.key === today);
  if (todayGroup) return todayGroup.key;

  // Keys are "YYYY-MM-DD" in venue-local terms, so comparing them as strings
  // asks the right question ("is this matchday still ahead there?") without
  // having to build a venue-local midnight instant.
  const upcoming = dated.find((g) => g.key >= today);

  return (upcoming ?? dated[dated.length - 1]).key;
}

/** Group matches into ordered per-day buckets; undated matches go last. */
export function groupByDate(matches: Match[], tz: string): DateGroup[] {
  const dated = matches
    .filter((m) => m.scheduled_at)
    .sort((a, b) => new Date(a.scheduled_at!).getTime() - new Date(b.scheduled_at!).getTime());
  const undated = matches.filter((m) => !m.scheduled_at);

  const groups: DateGroup[] = [];
  for (const m of dated) {
    const key = dateKeyOf(m.scheduled_at, tz);
    let g = groups.find((x) => x.key === key);
    if (!g) {
      g = { key, iso: m.scheduled_at, list: [] };
      groups.push(g);
    }
    g.list.push(m);
  }
  if (undated.length) groups.push({ key: "tbd", iso: null, list: undated });

  return groups;
}
