"use client";

import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { CalendarClock, ListOrdered } from "lucide-react";

import { getPublicMatches, getPublicStandings } from "@/lib/api/matches";
import { StandingsTable } from "./standings-table";
import { cn } from "@/lib/utils";
import type { Match } from "@/types/api";

/**
 * Public schedule + standings for an event. Renders nothing until the organizer
 * has generated a schedule.
 */
export function PublicResults({ orgSlug, eventSlug }: { orgSlug: string; eventSlug: string }) {
  const [tab, setTab] = useState<"schedule" | "standings">("schedule");

  const matchesQuery = useQuery({
    queryKey: ["public-matches", orgSlug, eventSlug],
    queryFn: () => getPublicMatches(orgSlug, eventSlug),
    retry: false,
  });
  const standingsQuery = useQuery({
    queryKey: ["public-standings", orgSlug, eventSlug],
    queryFn: () => getPublicStandings(orgSlug, eventSlug),
    retry: false,
  });

  const matches = matchesQuery.data ?? [];
  if (matchesQuery.isLoading || matches.length === 0) return null;

  const rounds = matches.reduce<Record<number, Match[]>>((acc, m) => {
    (acc[m.round] ??= []).push(m);
    return acc;
  }, {});

  return (
    <section className="section" style={{ paddingTop: 0 }}>
      <div className="container">
        <div className="esection-title">
          <h2 className="section-title" style={{ margin: 0 }}>
            Jadwal &amp; Klasemen
          </h2>
        </div>

        <div className="mb-6 inline-flex items-center gap-1 rounded-full border border-border bg-[var(--surface)] p-1 text-sm font-semibold">
          {([
            ["schedule", "Jadwal", CalendarClock],
            ["standings", "Klasemen", ListOrdered],
          ] as const).map(([key, label, Icon]) => (
            <button
              key={key}
              onClick={() => setTab(key)}
              className={cn(
                "inline-flex items-center gap-1.5 rounded-full px-4 py-1.5 transition-colors",
                tab === key ? "bg-[var(--brand-600)] text-white" : "text-muted-foreground hover:text-foreground"
              )}
            >
              <Icon className="h-4 w-4" />
              {label}
            </button>
          ))}
        </div>

        {tab === "schedule" ? (
          <div style={{ maxWidth: 760 }}>
            {Object.entries(rounds).map(([round, list]) => (
              <div key={round}>
                <div className="match-day">Putaran {round}</div>
                {list.map((m) => {
                  const done = m.status === "finished" && m.home_score !== null && m.away_score !== null;
                  return (
                    <div key={m.id} className="match-card" style={{ gridTemplateColumns: "1fr auto 1fr" }}>
                      <span style={{ textAlign: "right", fontWeight: 600 }}>{m.home_team?.name ?? "—"}</span>
                      <span
                        style={{
                          fontFamily: "var(--font-display)",
                          fontWeight: 800,
                          fontVariantNumeric: "tabular-nums",
                          color: done ? "var(--text)" : "var(--text-muted)",
                        }}
                      >
                        {done ? `${m.home_score} – ${m.away_score}` : "vs"}
                      </span>
                      <span style={{ fontWeight: 600 }}>{m.away_team?.name ?? "—"}</span>
                    </div>
                  );
                })}
              </div>
            ))}
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
