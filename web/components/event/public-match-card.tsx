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
 * Per-set scores for one side, as scoreboard columns beside the name.
 *
 * One rule governs every set figure on this card: **whoever took the set reads
 * solid, the other side stays muted.** Emphasis per *cell*, not per row — a row
 * dimmed wholesale by who won the match turns "21 19 15" into three losing
 * numbers even though the first set was won, which is exactly what made the
 * scores read as noise.
 */
function SetColumns({ sets, side }: { sets: { home: number; away: number }[]; side: "home" | "away" }) {
  const other = side === "home" ? "away" : "home";

  return (
    <span className="match-sets" aria-label={`Skor set: ${sets.map((s) => s[side]).join(", ")}`}>
      {sets.map((s, i) => (
        <span key={i} className={cn("set-cell", s[side] > s[other] && "set-cell--won")}>
          {s[side]}
        </span>
      ))}
    </span>
  );
}

/**
 * The partai of a squad tie, under the two squad names: what the "3 – 0" above
 * is actually made of.
 *
 * The same set rule as above, one level down — the winning figure of each pair
 * is solid. Who played the partai is left to the detail dialog, where there is
 * room for names.
 */
function RubberLines({ match: m }: { match: Match }) {
  // Narrowed rather than asserted below: a partai with no sets has not been
  // played, and there is nothing to show for it yet.
  const played = (m.rubbers ?? []).filter(
    (r): r is typeof r & { sets: { home: number; away: number }[] } => !!r.sets?.length,
  );

  if (played.length === 0) {
    return null;
  }

  return (
    <div className="match-rubbers">
      {played.map((r) => (
        <div key={r.id} className="rubber-line">
          <span className="rubber-label truncate">{r.label}</span>
          <span className="rubber-sets">
            {r.sets.map((s, i) => (
              <span key={i} className="rubber-set">
                <span className={cn(s.home > s.away && "set-cell--won")}>{s.home}</span>
                <span className="rubber-dash">–</span>
                <span className={cn(s.away > s.home && "set-cell--won")}>{s.away}</span>
              </span>
            ))}
          </span>
        </div>
      ))}
    </div>
  );
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
  categoryLabel,
  phase,
  onClick,
}: {
  match: Match;
  /** Which category this fixture belongs to; only shown in the combined list. */
  categoryLabel?: string;
  /**
   * Phase in words — "Grup A", "Perempat Final", "Perebutan Juara 3". Computed
   * by the caller because it needs the whole round to know which one it is: a
   * round number alone can't tell a semifinal from a Round of 16.
   */
  phase: string;
  onClick: () => void;
}) {
  const tz = useEventTimezone();
  const done = m.status === "finished" && m.home_score !== null && m.away_score !== null;
  const cancelled = m.status === "cancelled";
  const time = timeOf(m.scheduled_at, tz);
  const winner = done ? matchWinnerId(m) : null;
  const sets = done && m.sets?.length ? m.sets : null;

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
          {sets && <SetColumns sets={sets} side="home" />}
          {done && <span className="sc">{m.home_score}</span>}
        </div>
        <div className={cn("match-team", winner && winner !== m.away_team_id && "lose")}>
          <Crest name={m.away_team?.name ?? "TBD"} logoUrl={m.away_team?.logo_url} />
          <span className="truncate">{m.away_team?.name ?? "TBD"}</span>
          {sets && <SetColumns sets={sets} side="away" />}
          {done && <span className="sc">{m.away_score}</span>}
        </div>
        <RubberLines match={m} />
      </div>
      <div className="match-meta">
        <PublicStatusBadge status={m.status} />
        {categoryLabel && <small className="pill">{categoryLabel}</small>}
        <small>{phase}</small>
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
