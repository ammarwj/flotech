"use client";

import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { LayoutList, CalendarRange } from "lucide-react";

import { getPublicMatches, getPublicStandings, getPublicLeaderboard } from "@/lib/api/matches";
import { buildMatchSections, isKnockout as isKnockoutFormat, isDoubleElim } from "@/lib/bracket";
import { matchScoreText } from "@/lib/scoring";
import { StandingsTable } from "./standings-table";
import { BracketView } from "./bracket-view";
import { DoubleBracketView } from "./double-bracket-view";
import { LeaderboardTable } from "./leaderboard-table";
import { MatchCalendar } from "./match-calendar";
import { cn } from "@/lib/utils";
import type { TournamentFormat } from "@/types/api";

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
  format,
  activeTab,
}: {
  orgSlug: string;
  eventSlug: string;
  format: TournamentFormat;
  activeTab: ResultsTab;
}) {
  const isKnockout = isKnockoutFormat(format);
  const isDouble = isDoubleElim(format);
  const [scheduleView, setScheduleView] = useState<"list" | "calendar">("list");

  const matchesQuery = useQuery({
    queryKey: ["public-matches", orgSlug, eventSlug],
    queryFn: () => getPublicMatches(orgSlug, eventSlug),
    retry: false,
  });
  const standingsQuery = useQuery({
    queryKey: ["public-standings", orgSlug, eventSlug],
    queryFn: () => getPublicStandings(orgSlug, eventSlug),
    retry: false,
    enabled: !isKnockout,
  });
  const leaderboardQuery = useQuery({
    queryKey: ["public-leaderboard", orgSlug, eventSlug],
    queryFn: () => getPublicLeaderboard(orgSlug, eventSlug),
    retry: false,
  });

  const matches = matchesQuery.data ?? [];
  if (matchesQuery.isLoading) return null;
  if (matches.length === 0) {
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

  const sections = buildMatchSections(matches, isKnockout, isDouble);

  return (
    <section className="section" style={{ paddingTop: 24 }}>
      <div className="container">
        {activeTab === "bracket" ? (
          <div className="overflow-x-auto pb-2">
            {isDouble ? <DoubleBracketView matches={matches} /> : <BracketView matches={matches} />}
          </div>
        ) : activeTab === "schedule" ? (
          <div style={{ maxWidth: 760 }}>
            <div className="mb-4 inline-flex items-center gap-1 rounded-lg border border-border bg-[var(--surface)] p-0.5 text-xs font-semibold">
              {([
                ["list", "List", LayoutList],
                ["calendar", "Kalender", CalendarRange],
              ] as const).map(([key, label, Icon]) => (
                <button
                  key={key}
                  onClick={() => setScheduleView(key)}
                  className={cn(
                    "inline-flex items-center gap-1.5 rounded-md px-3 py-1 transition-colors",
                    scheduleView === key ? "bg-[var(--brand-600)] text-white" : "text-muted-foreground hover:text-foreground"
                  )}
                >
                  <Icon className="h-3.5 w-3.5" />
                  {label}
                </button>
              ))}
            </div>

            {scheduleView === "calendar" ? (
              <MatchCalendar matches={matches} />
            ) : (
              sections.map(([label, list]) => (
                <div key={label}>
                  <div className="match-day">{label}</div>
                  {list.map((m) => {
                    const done = m.status === "finished" && m.home_score !== null && m.away_score !== null;
                    const bye = !!m.home_team && !m.away_team && m.status === "finished";
                    const score = matchScoreText(m);
                    return (
                      <div key={m.id} className="match-card" style={{ gridTemplateColumns: "1fr auto 1fr" }}>
                        <span style={{ textAlign: "right", fontWeight: 600 }}>{m.home_team?.name ?? "TBD"}</span>
                        <span
                          style={{
                            fontFamily: "var(--font-display)",
                            fontWeight: 800,
                            fontVariantNumeric: "tabular-nums",
                            textAlign: "center",
                            color: done ? "var(--text)" : "var(--text-muted)",
                          }}
                        >
                          {done ? score.main : bye ? "bye" : "vs"}
                          {done && score.detail && (
                            <span style={{ display: "block", fontSize: 11, fontWeight: 400, color: "var(--text-muted)" }}>
                              {score.detail}
                            </span>
                          )}
                        </span>
                        <span style={{ fontWeight: 600 }}>{bye ? "—" : m.away_team?.name ?? "TBD"}</span>
                      </div>
                    );
                  })}
                </div>
              ))
            )}
          </div>
        ) : activeTab === "stats" ? (
          <div style={{ maxWidth: 640 }}>
            {leaderboardQuery.data && <LeaderboardTable leaderboard={leaderboardQuery.data} />}
          </div>
        ) : (
          <div style={{ maxWidth: 760 }}>
            <StandingsTable standings={standingsQuery.data ?? []} highlight={2} />
          </div>
        )}
      </div>
    </section>
  );
}
