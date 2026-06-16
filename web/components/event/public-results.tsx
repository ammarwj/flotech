"use client";

import { useState } from "react";
import { useQuery } from "@tanstack/react-query";

import { getPublicMatches, getPublicStandings, getPublicLeaderboard } from "@/lib/api/matches";
import { isKnockout as isKnockoutFormat, isDoubleElim, crestGradient, matchWinnerId } from "@/lib/bracket";
import { StandingsTable } from "./standings-table";
import { BracketView } from "./bracket-view";
import { DoubleBracketView } from "./double-bracket-view";
import { LeaderboardTable } from "./leaderboard-table";
import { cn } from "@/lib/utils";
import type { Match, TournamentFormat } from "@/types/api";

export type ResultsTab = "schedule" | "standings" | "bracket" | "stats";

function timeOf(iso: string | null): string | null {
  if (!iso) return null;
  const d = new Date(iso);
  return `${String(d.getHours()).padStart(2, "0")}:${String(d.getMinutes()).padStart(2, "0")}`;
}

function dateKeyOf(iso: string | null): string {
  if (!iso) return "tbd";
  const d = new Date(iso);
  return `${d.getFullYear()}-${d.getMonth()}-${d.getDate()}`;
}

function fullDateLabel(iso: string | null): string {
  if (!iso) return "Jadwal menyusul";
  return new Date(iso).toLocaleDateString("id-ID", { weekday: "long", day: "numeric", month: "long", year: "numeric" });
}

function tabDateLabel(iso: string | null): string {
  if (!iso) return "TBD";
  return new Date(iso).toLocaleDateString("id-ID", { weekday: "short", day: "numeric", month: "short" });
}

/** Team crest: real logo when uploaded, gradient fallback otherwise. */
function Crest({ name, logoUrl }: { name: string; logoUrl: string | null | undefined }) {
  if (logoUrl) {
    // eslint-disable-next-line @next/next/no-img-element
    return <img className="crest" src={logoUrl} alt={name} style={{ objectFit: "cover" }} />;
  }
  return <span className="crest" style={{ background: crestGradient(name) }} />;
}

type DateGroup = { key: string; iso: string | null; list: Match[] };

/** Group matches into ordered per-day buckets; undated matches go last. */
function groupByDate(matches: Match[]): DateGroup[] {
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
  const activeDateKey = dateKey && dateGroups.some((g) => g.key === dateKey) ? dateKey : dateGroups[0]?.key;
  const activeGroup = dateGroups.find((g) => g.key === activeDateKey);

  return (
    <section className="section" style={{ paddingTop: 24 }}>
      <div className="container">
        {activeTab === "bracket" ? (
          <div className="overflow-x-auto pb-2">
            {isDouble ? <DoubleBracketView matches={matches} /> : <BracketView matches={matches} />}
          </div>
        ) : activeTab === "schedule" ? (
          <div style={{ maxWidth: 760 }}>
            {/* date sub-tabs */}
            <div className="mb-5 flex flex-wrap gap-1.5">
              {dateGroups.map((g) => (
                <button
                  key={g.key}
                  onClick={() => setDateKey(g.key)}
                  className={cn(
                    "rounded-full border px-3.5 py-1.5 text-xs font-semibold transition-colors",
                    activeDateKey === g.key
                      ? "border-transparent bg-[var(--brand-600)] text-white"
                      : "border-border bg-[var(--surface)] text-muted-foreground hover:text-foreground"
                  )}
                >
                  {tabDateLabel(g.iso)}
                </button>
              ))}
            </div>

            {activeGroup && (
              <>
                <div className="match-day">{fullDateLabel(activeGroup.iso)}</div>
                {activeGroup.list.map((m) => {
                  const done = m.status === "finished" && m.home_score !== null && m.away_score !== null;
                  const live = m.status === "ongoing";
                  const time = timeOf(m.scheduled_at);
                  const winner = done ? matchWinnerId(m) : null;
                  const metaLabel = m.group_name ?? (isKnockout ? `Babak ${m.round}` : `Pekan ${m.round}`);
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
                        {m.venue && <small>{m.venue}</small>}
                      </div>
                    </div>
                  );
                })}
              </>
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
