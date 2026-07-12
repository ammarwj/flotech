"use client";

import { useState } from "react";
import { useQuery } from "@tanstack/react-query";

import { getPublicMatches, getPublicStandings, getPublicLeaderboard } from "@/lib/api/matches";
import {
  isKnockout as isKnockoutFormat,
  isDoubleElim,
  isHybrid as isHybridFormat,
  crestGradient,
  matchWinnerId,
  wentToPenalties,
} from "@/lib/bracket";
import { hybridConfig, knockoutMatches } from "@/lib/hybrid";
import { defaultDateKey, fullDateLabel, groupByDate, timeOf } from "@/lib/match-dates";
import { MatchDayTabs } from "./match-day-tabs";
import { StandingsTable } from "./standings-table";
import { GroupStandings } from "./group-standings";
import { BracketView } from "./bracket-view";
import { DoubleBracketView } from "./double-bracket-view";
import { LeaderboardTable } from "./leaderboard-table";
import { cn } from "@/lib/utils";
import type { BracketConfig, TournamentFormat } from "@/types/api";

export type ResultsTab = "schedule" | "standings" | "bracket" | "stats";

/** Team crest: real logo when uploaded, gradient fallback otherwise. */
function Crest({ name, logoUrl }: { name: string; logoUrl: string | null | undefined }) {
  if (logoUrl) {
    // eslint-disable-next-line @next/next/no-img-element
    return <img className="crest" src={logoUrl} alt={name} style={{ objectFit: "cover" }} />;
  }
  return <span className="crest" style={{ background: crestGradient(name) }} />;
}

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
  bracketConfig,
  activeTab,
}: {
  orgSlug: string;
  eventSlug: string;
  format: TournamentFormat;
  bracketConfig?: BracketConfig | null;
  activeTab: ResultsTab;
}) {
  const isKnockout = isKnockoutFormat(format);
  const isDouble = isDoubleElim(format);
  const isHybrid = isHybridFormat(format);
  const config = hybridConfig(bracketConfig);
  const [dateKey, setDateKey] = useState<string | null>(null);

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
                {activeGroup.list.map((m) => {
                  const done = m.status === "finished" && m.home_score !== null && m.away_score !== null;
                  const live = m.status === "ongoing";
                  const time = timeOf(m.scheduled_at);
                  const winner = done ? matchWinnerId(m) : null;
                  const metaLabel = m.group_name
                    ? `Grup ${m.group_name}`
                    : isKnockout || m.stage === "knockout"
                      ? `Babak ${m.round}`
                      : `Pekan ${m.round}`;
                  return (
                    <div key={m.id} className="match-card">
                      <div className="match-time">
                        {live ? (
                          <span className="badge badge-live">
                            <span className="dot" /> LIVE
                          </span>
                        ) : (
                          <>
                            <b>{time ?? "TBD"}</b>
                            {time && <small>WIB</small>}
                          </>
                        )}
                      </div>
                      <div className="match-teams">
                        <div className={cn("match-team", winner && winner !== m.home_team_id && "lose")}>
                          <Crest name={m.home_team?.name ?? "TBD"} logoUrl={m.home_team?.logo_url} />
                          <span className="truncate">{m.home_team?.name ?? "TBD"}</span>
                          {done && <span className="sc">{m.home_score}</span>}
                        </div>
                        <div className={cn("match-team", winner && winner !== m.away_team_id && "lose")}>
                          <Crest name={m.away_team?.name ?? "TBD"} logoUrl={m.away_team?.logo_url} />
                          <span className="truncate">{m.away_team?.name ?? "TBD"}</span>
                          {done && <span className="sc">{m.away_score}</span>}
                        </div>
                      </div>
                      <div className="match-meta">
                        <small>{metaLabel}</small>
                        {wentToPenalties(m) ? (
                          <small>
                            Pen {m.home_penalty}–{m.away_penalty}
                          </small>
                        ) : (
                          m.venue && <small>{m.venue}</small>
                        )}
                      </div>
                    </div>
                  );
                })}
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
    </section>
  );
}
