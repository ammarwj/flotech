"use client";

import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { CalendarClock, ListOrdered, Network, Goal, LayoutList, CalendarRange } from "lucide-react";

import { getPublicMatches, getPublicStandings, getPublicLeaderboard } from "@/lib/api/matches";
import { knockoutRoundLabel, groupByRound } from "@/lib/bracket";
import { matchScoreText } from "@/lib/scoring";
import { StandingsTable } from "./standings-table";
import { BracketView } from "./bracket-view";
import { LeaderboardTable } from "./leaderboard-table";
import { MatchCalendar } from "./match-calendar";
import { cn } from "@/lib/utils";
import type { TournamentFormat } from "@/types/api";

type Tab = "schedule" | "standings" | "bracket" | "stats";

/**
 * Public schedule + standings/bracket for an event. Renders nothing until the
 * organizer has generated a schedule.
 */
export function PublicResults({
  orgSlug,
  eventSlug,
  format,
}: {
  orgSlug: string;
  eventSlug: string;
  format: TournamentFormat;
}) {
  const isKnockout = format === "knockout_single";
  const [tab, setTab] = useState<Tab | null>(null);
  const [scheduleView, setScheduleView] = useState<"list" | "calendar">("list");
  const activeTab: Tab = tab ?? (isKnockout ? "bracket" : "schedule");

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
  if (matchesQuery.isLoading || matches.length === 0) return null;

  const rounds = groupByRound(matches);
  const tabs: [Tab, string, typeof CalendarClock][] = isKnockout
    ? [
        ["bracket", "Bracket", Network],
        ["schedule", "Jadwal", CalendarClock],
        ["stats", "Statistik", Goal],
      ]
    : [
        ["schedule", "Jadwal", CalendarClock],
        ["standings", "Klasemen", ListOrdered],
        ["stats", "Statistik", Goal],
      ];

  return (
    <section className="section" style={{ paddingTop: 0 }}>
      <div className="container">
        <div className="esection-title">
          <h2 className="section-title" style={{ margin: 0 }}>
            Jadwal &amp; {isKnockout ? "Bracket" : "Klasemen"}
          </h2>
        </div>

        <div className="mb-6 inline-flex items-center gap-1 rounded-full border border-border bg-[var(--surface)] p-1 text-sm font-semibold">
          {tabs.map(([key, label, Icon]) => (
            <button
              key={key}
              onClick={() => setTab(key)}
              className={cn(
                "inline-flex items-center gap-1.5 rounded-full px-4 py-1.5 transition-colors",
                activeTab === key ? "bg-[var(--brand-600)] text-white" : "text-muted-foreground hover:text-foreground"
              )}
            >
              <Icon className="h-4 w-4" />
              {label}
            </button>
          ))}
        </div>

        {activeTab === "bracket" ? (
          <div className="overflow-x-auto pb-2">
            <BracketView matches={matches} />
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
              rounds.map(([round, list]) => (
              <div key={round}>
                <div className="match-day">{isKnockout ? knockoutRoundLabel(list.length) : `Putaran ${round}`}</div>
                {list.map((m) => {
                  const done = m.status === "finished" && m.home_score !== null && m.away_score !== null;
                  const bye = !!m.home_team && !m.away_team;
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
