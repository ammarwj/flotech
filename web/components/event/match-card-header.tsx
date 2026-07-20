"use client";

import { MatchStatusBadge } from "@/components/shared/status-badge";
import { Badge } from "@/components/ui/badge";
import { MatchConfirmBar } from "./match-confirm-bar";
import { MatchStatusActions } from "./match-status-actions";
import type { Match } from "@/types/api";

/**
 * The one row every match card opens with: what state the fixture is in, and
 * the moves available from it.
 *
 * MatchCard has five render branches (walkover, TBD, set-based, goal-based,
 * cancelled) — this exists so the badge and the actions are written once rather
 * than pasted five times and drifting.
 */
export function MatchCardHeader({
  orgId,
  eventId,
  match,
  knockout,
  phase,
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
}) {
  return (
    <div className="flex flex-wrap items-center justify-between gap-2 border-b border-border pb-2">
      <div className="flex flex-wrap items-center gap-2">
        <MatchStatusBadge match={match} />
        {phase && (
          <Badge variant="outline" className="font-medium">
            {phase}
          </Badge>
        )}
      </div>
      <div className="flex flex-wrap items-center gap-1">
        {/* Renders nothing until the match is finished with a scoreline. */}
        <MatchConfirmBar orgId={orgId} eventId={eventId} match={match} />
        <MatchStatusActions orgId={orgId} eventId={eventId} match={match} knockout={knockout} />
      </div>
    </div>
  );
}
