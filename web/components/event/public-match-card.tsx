"use client";

import { crestGradient, matchWinnerId, wentToPenalties } from "@/lib/bracket";
import { timeOf, tzLabel } from "@/lib/match-dates";
import { useEventTimezone } from "./event-timezone";
import { cn } from "@/lib/utils";
import { PublicStatusBadge } from "./public-status-badge";
import type { Match } from "@/types/api";

/** Team crest: real logo when uploaded, gradient fallback otherwise. */
function Crest({ name, logoUrl }: { name: string; logoUrl: string | null | undefined }) {
  if (logoUrl) {
    // eslint-disable-next-line @next/next/no-img-element
    return <img className="crest" src={logoUrl} alt={name} style={{ objectFit: "cover" }} />;
  }
  return <span className="crest" style={{ background: crestGradient(name) }} />;
}

/**
 * One fixture on the public schedule. Rendered both per-category and in the
 * combined "Semua" list, which is the only place `categoryLabel` is set.
 *
 * The whole card is the button: it holds no interactive children, so there is
 * no nested click to swallow.
 *
 * `.match-card` is shared with the team grid on the public page, so the mobile
 * restack in event-shell.css hangs off `--fixture` rather than the base class.
 */
export function PublicMatchCard({
  match: m,
  knockout,
  categoryLabel,
  onClick,
}: {
  match: Match;
  /** A tie that must produce a winner — changes the round label wording. */
  knockout: boolean;
  /** Which category this fixture belongs to; only shown in the combined list. */
  categoryLabel?: string;
  onClick: () => void;
}) {
  const tz = useEventTimezone();
  const done = m.status === "finished" && m.home_score !== null && m.away_score !== null;
  const cancelled = m.status === "cancelled";
  const time = timeOf(m.scheduled_at, tz);
  const winner = done ? matchWinnerId(m) : null;
  const metaLabel = m.group_name
    ? `Grup ${m.group_name}`
    : knockout || m.stage === "knockout"
      ? `Babak ${m.round}`
      : `Pekan ${m.round}`;

  return (
    <button
      type="button"
      onClick={onClick}
      className={cn("match-card match-card--fixture", cancelled && "match-card--cancelled")}
      aria-label={`Detail ${m.home_team?.name ?? "TBD"} vs ${m.away_team?.name ?? "TBD"}`}
    >
      {/* The kickoff time always stays — the status chip lives in the meta
          column so a live or cancelled fixture doesn't lose when it was due. */}
      <div className="match-time">
        <b>{time ?? "TBD"}</b>
        {time && <small>{tzLabel(tz)}</small>}
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
        <PublicStatusBadge status={m.status} />
        {categoryLabel && <small className="pill">{categoryLabel}</small>}
        <small>{metaLabel}</small>
        {wentToPenalties(m) ? (
          <small>
            Pen {m.home_penalty}–{m.away_penalty}
          </small>
        ) : (
          m.venue && <small>{m.venue}</small>
        )}
      </div>
    </button>
  );
}
