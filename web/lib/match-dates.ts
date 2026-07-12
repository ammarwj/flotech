import type { Match } from "@/types/api";

export type DateGroup = { key: string; iso: string | null; list: Match[] };

/** Kickoff time, "HH:mm". */
export function timeOf(iso: string | null): string | null {
  if (!iso) return null;
  const d = new Date(iso);
  return `${String(d.getHours()).padStart(2, "0")}:${String(d.getMinutes()).padStart(2, "0")}`;
}

/** Stable per-day key; undated matches share the "tbd" bucket. */
export function dateKeyOf(iso: string | null): string {
  if (!iso) return "tbd";
  const d = new Date(iso);
  return `${d.getFullYear()}-${d.getMonth()}-${d.getDate()}`;
}

export function fullDateLabel(iso: string | null): string {
  if (!iso) return "Jadwal menyusul";
  return new Date(iso).toLocaleDateString("id-ID", {
    weekday: "long",
    day: "numeric",
    month: "long",
    year: "numeric",
  });
}

export function tabDateLabel(iso: string | null): string {
  if (!iso) return "TBD";
  return new Date(iso).toLocaleDateString("id-ID", {
    weekday: "short",
    day: "numeric",
    month: "short",
  });
}

/**
 * The matchday to land on when nothing is picked yet: today, else the next day
 * still to come, else the last day played (an event that is already over opens
 * on its final matchday). Undated fixtures are never the default.
 */
export function defaultDateKey(groups: DateGroup[]): string | undefined {
  const dated = groups.filter((g) => g.iso);
  if (dated.length === 0) return groups[0]?.key;

  const today = dateKeyOf(new Date().toISOString());
  const todayGroup = dated.find((g) => g.key === today);
  if (todayGroup) return todayGroup.key;

  const startOfToday = new Date();
  startOfToday.setHours(0, 0, 0, 0);

  const upcoming = dated.find((g) => new Date(g.iso!).getTime() >= startOfToday.getTime());

  return (upcoming ?? dated[dated.length - 1]).key;
}

/** Group matches into ordered per-day buckets; undated matches go last. */
export function groupByDate(matches: Match[]): DateGroup[] {
  const dated = matches
    .filter((m) => m.scheduled_at)
    .sort((a, b) => new Date(a.scheduled_at!).getTime() - new Date(b.scheduled_at!).getTime());
  const undated = matches.filter((m) => !m.scheduled_at);

  const groups: DateGroup[] = [];
  for (const m of dated) {
    const key = dateKeyOf(m.scheduled_at);
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
