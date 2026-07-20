"use client";

import { useState } from "react";
import { useQueries } from "@tanstack/react-query";

import { getPublicMatches } from "@/lib/api/matches";
import { isKnockout as isKnockoutFormat, phaseLabel } from "@/lib/bracket";
import { defaultDateKey, fullDateLabel, groupByDate } from "@/lib/match-dates";
import { useEventTimezone } from "./event-timezone";
import { MatchDayTabs } from "./match-day-tabs";
import { PublicMatchCard } from "./public-match-card";
import { MatchDetailDialog } from "./match-detail-dialog";
import type { EventCategory, Match } from "@/types/api";

/**
 * Every category's fixtures in one chronological list — the "Semua" filter.
 *
 * Standings, brackets and the leaderboard have no combined form (a bracket
 * belongs to exactly one category), so this panel is schedule-only and the
 * parent offers the "Semua" pill on the schedule tab alone.
 */
export function PublicAllSchedule({
  orgSlug,
  eventSlug,
  categories,
}: {
  orgSlug: string;
  eventSlug: string;
  categories: EventCategory[];
}) {
  const tz = useEventTimezone();
  const [dateKey, setDateKey] = useState<string | null>(null);
  const [open, setOpen] = useState<{ match: Match; categoryLabel: string } | null>(null);

  // Fetched per category rather than through one event-wide endpoint: the cache
  // keys match the single-category panel exactly, so switching between "Semua"
  // and one category refetches nothing.
  const results = useQueries({
    queries: categories.map((c) => ({
      queryKey: ["public-matches", orgSlug, eventSlug, c.slug],
      queryFn: () => getPublicMatches(orgSlug, eventSlug, c.slug),
      retry: false,
    })),
  });

  const loading = results.some((r) => r.isLoading);

  // Which category a fixture came from is known from the query it arrived in —
  // no need for the API to label each match.
  const byMatchId = new Map<string, EventCategory>();
  // Phase has to be worked out inside one category's fixtures: the combined
  // list mixes categories, and "how many ties are in this round" is only
  // meaningful within the draw it belongs to.
  const phaseById = new Map<string, string>();
  const matches: Match[] = [];
  results.forEach((r, i) => {
    const own = r.data ?? [];
    const knockout = isKnockoutFormat(categories[i]?.engine ?? null);
    for (const m of own) {
      byMatchId.set(m.id, categories[i]);
      phaseById.set(m.id, phaseLabel(m, own, knockout));
      matches.push(m);
    }
  });

  const dateGroups = groupByDate(matches, tz);
  const activeDateKey =
    dateKey && dateGroups.some((g) => g.key === dateKey) ? dateKey : defaultDateKey(dateGroups, tz);
  const activeGroup = dateGroups.find((g) => g.key === activeDateKey);

  return (
    <section className="section" style={{ paddingTop: 24 }}>
      <div className="container">
        {loading ? (
          <div className="card" style={{ padding: 40, textAlign: "center", color: "var(--text-muted)" }}>
            Memuat jadwal…
          </div>
        ) : matches.length === 0 ? (
          <div className="card" style={{ padding: 40, textAlign: "center", color: "var(--text-muted)" }}>
            Jadwal pertandingan belum tersedia. Nantikan setelah penyelenggara menyusun jadwal.
          </div>
        ) : (
          <div style={{ maxWidth: 760 }}>
            <MatchDayTabs groups={dateGroups} activeKey={activeDateKey} onSelect={setDateKey} />

            {activeGroup && (
              <>
                <div className="match-day">{fullDateLabel(activeGroup.iso, tz)}</div>
                {activeGroup.list.map((m) => {
                  const category = byMatchId.get(m.id);
                  return (
                    <PublicMatchCard
                      key={m.id}
                      match={m}
                      phase={phaseById.get(m.id) ?? ""}
                      categoryLabel={category?.name}
                      onClick={() => setOpen({ match: m, categoryLabel: category?.name ?? "" })}
                    />
                  );
                })}
              </>
            )}
          </div>
        )}
      </div>

      {open && (
        <MatchDetailDialog
          match={open.match}
          phase={phaseById.get(open.match.id) ?? ""}
          orgSlug={orgSlug}
          eventSlug={eventSlug}
          categoryLabel={open.categoryLabel || undefined}
          onClose={() => setOpen(null)}
        />
      )}
    </section>
  );
}
