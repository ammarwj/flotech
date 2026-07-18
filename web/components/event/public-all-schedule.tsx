"use client";

import { useState } from "react";
import { useQueries } from "@tanstack/react-query";

import { getPublicMatches } from "@/lib/api/matches";
import { isKnockout as isKnockoutFormat } from "@/lib/bracket";
import { defaultDateKey, fullDateLabel, groupByDate } from "@/lib/match-dates";
import { MatchDayTabs } from "./match-day-tabs";
import { PublicMatchCard } from "./public-match-card";
import { MatchDetailDialog } from "./match-detail-dialog";
import type { EventCategory, Match } from "@/types/api";

/**
 * Every category's fixtures in one chronological list — the "Semua" filter.
 *
 * Standings, brackets and the leaderboard have no combined form (a bracket
 * belongs to exactly one category), so this panel is schedule-only and the
 * parent hides those tabs while it is showing.
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
  const matches: Match[] = [];
  results.forEach((r, i) => {
    for (const m of r.data ?? []) {
      byMatchId.set(m.id, categories[i]);
      matches.push(m);
    }
  });

  const dateGroups = groupByDate(matches);
  const activeDateKey =
    dateKey && dateGroups.some((g) => g.key === dateKey) ? dateKey : defaultDateKey(dateGroups);
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
                <div className="match-day">{fullDateLabel(activeGroup.iso)}</div>
                {activeGroup.list.map((m) => {
                  const category = byMatchId.get(m.id);
                  return (
                    <PublicMatchCard
                      key={m.id}
                      match={m}
                      knockout={isKnockoutFormat(category?.engine ?? null)}
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
          orgSlug={orgSlug}
          eventSlug={eventSlug}
          categoryLabel={open.categoryLabel || undefined}
          onClose={() => setOpen(null)}
        />
      )}
    </section>
  );
}
