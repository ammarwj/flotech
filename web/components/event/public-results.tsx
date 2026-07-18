"use client";

import { useState } from "react";
import { useQuery } from "@tanstack/react-query";

import { getPublicMatches, getPublicStandings, getPublicLeaderboard } from "@/lib/api/matches";
import {
  isKnockout as isKnockoutFormat,
  isDoubleElim,
  isHybrid as isHybridFormat,
} from "@/lib/bracket";
import { hybridConfig, knockoutMatches } from "@/lib/hybrid";
import { useCatalog } from "@/lib/hooks/use-catalog";
import { defaultDateKey, fullDateLabel, groupByDate } from "@/lib/match-dates";
import { MatchDayTabs } from "./match-day-tabs";
import { StandingsTable } from "./standings-table";
import { GroupStandings } from "./group-standings";
import { BracketView } from "./bracket-view";
import { DoubleBracketView } from "./double-bracket-view";
import { LeaderboardTable } from "./leaderboard-table";
import { PublicMatchCard } from "./public-match-card";
import { MatchDetailDialog } from "./match-detail-dialog";
import type { BracketConfig, FormatEngine, Match } from "@/types/api";

export type ResultsTab = "schedule" | "standings" | "bracket" | "stats";

/**
 * Public schedule / bracket / standings / stats panel for an event. The active
 * sub-tab is controlled by the parent (the event page renders the tab bar);
 * this component just renders the panel for `activeTab`. Shows an empty state
 * until the organizer has generated a schedule.
 */
export function PublicResults({
  orgSlug,
  eventSlug,
  categorySlug,
  engine,
  bracketConfig,
  activeTab,
}: {
  orgSlug: string;
  eventSlug: string;
  /** The competition category whose schedule/standings this panel shows. */
  categorySlug: string;
  /** The engine the category's format runs on — decides which panels make sense. */
  engine: FormatEngine | null;
  bracketConfig?: BracketConfig | null;
  activeTab: ResultsTab;
}) {
  const catalog = useCatalog();
  const isKnockout = isKnockoutFormat(engine);
  const isDouble = isDoubleElim(engine);
  const isHybrid = isHybridFormat(engine);
  const config = hybridConfig(
    bracketConfig,
    catalog.tiebreakers.map((t) => t.key),
  );
  const [dateKey, setDateKey] = useState<string | null>(null);
  const [openMatch, setOpenMatch] = useState<Match | null>(null);

  const matchesQuery = useQuery({
    queryKey: ["public-matches", orgSlug, eventSlug, categorySlug],
    queryFn: () => getPublicMatches(orgSlug, eventSlug, categorySlug),
    retry: false,
  });
  const standingsQuery = useQuery({
    queryKey: ["public-standings", orgSlug, eventSlug, categorySlug],
    queryFn: () => getPublicStandings(orgSlug, eventSlug, categorySlug),
    retry: false,
    enabled: !isKnockout,
  });
  const leaderboardQuery = useQuery({
    queryKey: ["public-leaderboard", orgSlug, eventSlug, categorySlug],
    queryFn: () => getPublicLeaderboard(orgSlug, eventSlug, categorySlug),
    retry: false,
  });

  const matches = matchesQuery.data ?? [];
  if (matchesQuery.isLoading) return null;
  // Standings are built from the drawn teams, not the fixtures, so they stay
  // readable before a schedule exists — every other panel needs matches.
  if (matches.length === 0 && activeTab !== "standings") {
    return (
      <section className="section" style={{ paddingTop: 24 }}>
        <div className="container">
          <div className="card" style={{ padding: 40, textAlign: "center", color: "var(--text-muted)" }}>
            Jadwal pertandingan belum tersedia. Nantikan setelah penyelenggara menyusun jadwal.
          </div>
        </div>
      </section>
    );
  }

  const dateGroups = groupByDate(matches);
  const activeDateKey =
    dateKey && dateGroups.some((g) => g.key === dateKey) ? dateKey : defaultDateKey(dateGroups);
  const activeGroup = dateGroups.find((g) => g.key === activeDateKey);

  return (
    <section className="section" style={{ paddingTop: 24 }}>
      <div className="container">
        {activeTab === "bracket" ? (
          isHybrid && knockoutMatches(matches).length === 0 ? (
            <div className="card" style={{ padding: 40, textAlign: "center", color: "var(--text-muted)" }}>
              Bracket knockout dibuat setelah fase grup selesai.
            </div>
          ) : (
            <div className="overflow-x-auto pb-2">
              {isDouble ? (
                <DoubleBracketView matches={matches} />
              ) : (
                <BracketView matches={isHybrid ? knockoutMatches(matches) : matches} />
              )}
            </div>
          )
        ) : activeTab === "schedule" ? (
          <div style={{ maxWidth: 760 }}>
            <MatchDayTabs groups={dateGroups} activeKey={activeDateKey} onSelect={setDateKey} />

            {activeGroup && (
              <>
                <div className="match-day">{fullDateLabel(activeGroup.iso)}</div>
                {activeGroup.list.map((m) => (
                  <PublicMatchCard
                    key={m.id}
                    match={m}
                    knockout={isKnockout}
                    onClick={() => setOpenMatch(m)}
                  />
                ))}
              </>
            )}
          </div>
        ) : activeTab === "stats" ? (
          <div style={{ maxWidth: 900 }}>
            {leaderboardQuery.data && <LeaderboardTable leaderboard={leaderboardQuery.data} />}
          </div>
        ) : (
          <div style={{ maxWidth: isHybrid ? 1120 : 760 }}>
            {isHybrid ? (
              <GroupStandings standings={standingsQuery.data ?? []} config={config} />
            ) : (
              <StandingsTable standings={standingsQuery.data ?? []} highlight={2} />
            )}
          </div>
        )}
      </div>

      {openMatch && (
        <MatchDetailDialog
          match={openMatch}
          orgSlug={orgSlug}
          eventSlug={eventSlug}
          onClose={() => setOpenMatch(null)}
        />
      )}
    </section>
  );
}
