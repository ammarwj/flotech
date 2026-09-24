"use client";

import { MatchStatusBadge } from "@/components/shared/status-badge";
import { Badge } from "@/components/ui/badge";
import { MatchConfirmBar } from "./match-confirm-bar";
import { MatchDisciplineNotice } from "./match-discipline-notice";
import { MatchLineupSheetButton } from "./match-lineup-sheet-button";
import { MatchReportButton } from "./match-report-button";
import { MatchStatusActions } from "./match-status-actions";
import type { DisciplineBan, DisciplineRules, Match, SportDef } from "@/types/api";

/**
 * The one row every match card opens with: what state the fixture is in, and
 * the moves available from it.
 *
 * MatchCard has five render branches (walkover, TBD, set-based, goal-based,
 * cancelled) — this exists so the badge and the actions are written once rather
 * than pasted five times and drifting. The suspension notice rides along for
 * exactly that reason: one place to put it, five branches that get it.
 */
export function MatchCardHeader({
  orgId,
  eventId,
  match,
  knockout,
  phase,
  bans,
  sport,
  disciplineRules,
}: {
  orgId: string;
  eventId: string;
  match: Match;
  knockout: boolean;
  /**
   * Which phase this fixture belongs to — "Grup A", "Semifinal". A matchday
   * mixes the groups together, so without this the card can't say which one it
   * is; the section heading only names the matchday.
   */
  phase?: string;
  /**
   * Players barred from this fixture by accumulated cards. Always empty for a
   * cancelled or unplayable fixture — the server never puts one in the map — so
   * no branch here has to remember to suppress it.
   *
   * Required, not optional-with-a-default, and that is the whole point: the one
   * caller has six render branches, and a defaulted prop let five of them get
   * wired while the sixth — the ordinary goal-based card, the one most fixtures
   * actually use — silently rendered no warning at all. A missing prop must be
   * a type error, not an empty array.
   */
  bans: DisciplineBan[];
  /** Names the cards; the sport owns its own labels. Null = not loaded yet. */
  sport: SportDef | null;
  disciplineRules: DisciplineRules | null;
}) {
  return (
    <div className="grid gap-2 border-b border-border pb-2">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex flex-wrap items-center gap-2">
          <MatchStatusBadge match={match} />
          {phase && (
            <Badge variant="outline" className="font-medium">
              {phase}
            </Badge>
          )}
        </div>
        <div className="flex flex-wrap items-center gap-1">
          {/*
            Only once both sides are known. That is not the print gate — the
            referee's approval is checked on the server and nowhere else — it is
            the fixture not existing yet: an empty bracket slot has no teams to
            list, so there is no sheet to ask for.
          */}
          {match.home_team_id && match.away_team_id && (
            <MatchLineupSheetButton orgId={orgId} matchId={match.id} />
          )}
          {/*
            And once there is a result, the report of it. Not the print gate
            either — the server refuses until the fixture is finished with a
            scoreline — but the same kind of condition as the line above: an
            unplayed fixture has nothing to report, so offering the button would
            be offering a refusal.
          */}
          {match.status === "finished" &&
            match.home_score !== null &&
            match.away_score !== null && (
              <MatchReportButton orgId={orgId} matchId={match.id} />
            )}
          {/* Renders nothing until the match is finished with a scoreline. */}
          <MatchConfirmBar orgId={orgId} eventId={eventId} match={match} />
          <MatchStatusActions orgId={orgId} eventId={eventId} match={match} knockout={knockout} />
        </div>
      </div>
      {/* Renders nothing when nobody is suspended. */}
      <MatchDisciplineNotice bans={bans} sport={sport} rules={disciplineRules} />
    </div>
  );
}
