"use client";

import { useEffect } from "react";
import { useQuery } from "@tanstack/react-query";
import { MapPin, X } from "lucide-react";

import { getPublicMatchStats } from "@/lib/api/matches";
import { crestGradient, matchWinnerId, wentToPenalties } from "@/lib/bracket";
import { fullDateLabel, timeOf, tzLabel } from "@/lib/match-dates";
import { useEventTimezone } from "./event-timezone";
import { cn } from "@/lib/utils";
import type { Match, PublicMatchStatTeam, StatColumn } from "@/types/api";

/**
 * Read-only detail of one fixture: the scoreline, then who did what. The stats
 * are aggregate counts per player — the data carries no minute of the event, so
 * this is "Budi — 2 gol", never a timeline.
 *
 * Mounted conditionally by the parent (no `open` prop), like TeamRosterDialog.
 */
export function MatchDetailDialog({
  match,
  orgSlug,
  eventSlug,
  categoryLabel,
  onClose,
}: {
  match: Match;
  orgSlug: string;
  eventSlug: string;
  /** Shown when the fixture was opened from the combined "Semua" list. */
  categoryLabel?: string;
  onClose: () => void;
}) {
  const tz = useEventTimezone();

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") onClose();
    };
    document.addEventListener("keydown", onKey);
    document.body.style.overflow = "hidden";
    return () => {
      document.removeEventListener("keydown", onKey);
      document.body.style.overflow = "";
    };
  }, [onClose]);

  const query = useQuery({
    queryKey: ["public-match-stats", orgSlug, eventSlug, match.id],
    queryFn: () => getPublicMatchStats(orgSlug, eventSlug, match.id),
    retry: false,
  });

  const done = match.status === "finished" && match.home_score !== null && match.away_score !== null;
  const winner = done ? matchWinnerId(match) : null;
  const time = timeOf(match.scheduled_at, tz);
  const columns = query.data?.columns ?? [];
  const sides = [query.data?.home_team, query.data?.away_team].filter(Boolean) as PublicMatchStatTeam[];
  const hasStats = sides.some((s) => s.players.length > 0);

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-label={`Detail ${match.home_team?.name ?? "TBD"} vs ${match.away_team?.name ?? "TBD"}`}
      onClick={onClose}
      className="fixed inset-0 z-50 flex items-end justify-center bg-black/60 p-0 backdrop-blur-sm sm:items-center sm:p-6"
    >
      <div
        onClick={(e) => e.stopPropagation()}
        className="flex max-h-[85vh] w-full max-w-lg flex-col overflow-hidden rounded-t-2xl border border-border bg-[var(--surface)] shadow-xl sm:rounded-2xl"
      >
        <div className="flex items-start gap-3 border-b border-border p-4">
          <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-center gap-2 text-xs" style={{ color: "var(--text-muted)" }}>
              {categoryLabel && <span className="pill">{categoryLabel}</span>}
              <span>
                {match.group_name
                  ? `Grup ${match.group_name}`
                  : match.stage === "knockout"
                    ? `Babak ${match.round}`
                    : `Pekan ${match.round}`}
              </span>
              {match.status === "ongoing" && (
                <span className="badge badge-live">
                  <span className="dot" /> LIVE
                </span>
              )}
            </div>
            <h3 className="mt-1 text-base font-bold">
              {match.home_team?.name ?? "TBD"} vs {match.away_team?.name ?? "TBD"}
            </h3>
            <div className="mt-1 flex flex-wrap items-center gap-3 text-xs" style={{ color: "var(--text-muted)" }}>
              <span>
                {fullDateLabel(match.scheduled_at, tz)}
                {time && ` · ${time} ${tzLabel(tz)}`}
              </span>
              {match.venue && (
                <span className="inline-flex items-center gap-1">
                  <MapPin className="h-3.5 w-3.5" />
                  {match.venue}
                </span>
              )}
            </div>
          </div>
          <button
            onClick={onClose}
            aria-label="Tutup"
            className="rounded-full p-1.5 text-muted-foreground transition-colors hover:bg-[var(--surface-2)] hover:text-foreground"
          >
            <X className="h-4 w-4" />
          </button>
        </div>

        {/* Scoreline */}
        <div className="flex items-center gap-3 border-b border-border p-4">
          <TeamLine
            name={match.home_team?.name ?? "TBD"}
            logoUrl={match.home_team?.logo_url}
            dimmed={!!winner && winner !== match.home_team_id}
          />
          <div className="shrink-0 text-center">
            <div
              className="text-2xl font-extrabold tabular-nums"
              style={{ fontFamily: "var(--font-display)" }}
            >
              {done ? `${match.home_score} – ${match.away_score}` : "vs"}
            </div>
            {wentToPenalties(match) && (
              <div className="text-xs" style={{ color: "var(--text-muted)" }}>
                Pen {match.home_penalty}–{match.away_penalty}
              </div>
            )}
          </div>
          <TeamLine
            name={match.away_team?.name ?? "TBD"}
            logoUrl={match.away_team?.logo_url}
            dimmed={!!winner && winner !== match.away_team_id}
            align="right"
          />
        </div>

        <div className="overflow-y-auto p-4">
          {query.isLoading ? (
            <p className="section-sub" style={{ margin: 0 }}>
              Memuat statistik…
            </p>
          ) : query.isError ? (
            <p className="section-sub" style={{ margin: 0 }}>
              Statistik pertandingan gagal dimuat.
            </p>
          ) : !hasStats ? (
            // The ordinary case for a fixture not yet played, or one whose
            // organizer hasn't filled the stats in — not an error.
            <p className="section-sub" style={{ margin: 0 }}>
              Statistik pemain belum tersedia untuk pertandingan ini.
            </p>
          ) : (
            <div className="flex flex-col gap-5">
              {sides.map((side) => (
                <SideStats key={side.id} side={side} columns={columns} />
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

function TeamLine({
  name,
  logoUrl,
  dimmed,
  align = "left",
}: {
  name: string;
  logoUrl?: string | null;
  dimmed: boolean;
  align?: "left" | "right";
}) {
  return (
    <div
      className={cn(
        "flex min-w-0 flex-1 items-center gap-2",
        align === "right" ? "flex-row-reverse text-right" : "text-left",
        dimmed && "opacity-60"
      )}
    >
      {logoUrl ? (
        // eslint-disable-next-line @next/next/no-img-element
        <img
          src={logoUrl}
          alt={name}
          className="shrink-0 object-cover"
          style={{ width: 30, height: 30, borderRadius: 8, border: "1px solid var(--border)" }}
        />
      ) : (
        <span
          className="shrink-0"
          style={{ width: 30, height: 30, borderRadius: 8, background: crestGradient(name) }}
        />
      )}
      <span className="min-w-0 flex-1 truncate text-sm font-semibold">{name}</span>
    </div>
  );
}

function SideStats({ side, columns }: { side: PublicMatchStatTeam; columns: StatColumn[] }) {
  if (side.players.length === 0) return null;

  // Only the stat kinds this team actually recorded, so a football match with
  // no cards doesn't render three empty columns.
  const used = columns.filter((c) => side.players.some((p) => (p.stats[c.key] ?? 0) > 0));

  return (
    <section className="min-w-0">
      <h4 className="mb-2 text-sm font-bold">{side.name}</h4>
      <ul className="flex flex-col gap-1.5">
        {side.players.map((p) => (
          <li
            key={p.id}
            className="flex items-center gap-3 rounded-xl border border-border p-2.5"
          >
            <span
              className="grid shrink-0 place-items-center text-xs font-bold"
              style={{
                width: 30,
                height: 30,
                borderRadius: 8,
                background: "var(--surface-2)",
                color: "var(--text-muted)",
              }}
            >
              {p.jersey_number ?? "–"}
            </span>
            <span className="min-w-0 flex-1 truncate text-sm font-semibold">{p.full_name}</span>
            <span className="flex shrink-0 flex-wrap items-center justify-end gap-1.5">
              {used.map((c) => {
                const v = p.stats[c.key] ?? 0;
                if (v === 0) return null;
                return (
                  <span key={c.key} className="pill" title={c.label}>
                    {v} {c.short}
                  </span>
                );
              })}
            </span>
          </li>
        ))}
      </ul>
    </section>
  );
}
