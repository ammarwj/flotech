import { useCallback, useEffect, useLayoutEffect, useRef, useState } from "react";
import { createPortal } from "react-dom";
import { Scale } from "lucide-react";

import { hasAdjustments, standingsColumns } from "@/lib/scoring";
import type { MatchTeamRef, StandingsContext, Standing } from "@/types/api";

/**
 * League table. `highlight` marks the top N rows in green.
 *
 * What N means is the caller's business, and it differs by format: in a hybrid
 * group it's "qualifies for the knockout", in a standalone league there is no
 * next stage to qualify for (generateKnockout() is hybrid-only, 422 otherwise)
 * so the only thing worth marking is the leader.
 *
 * Which columns follow "Tim" — Poin among them — is `context`'s business, see
 * standingsColumns(). Nothing about a sport is decided here; the only thing
 * this table knows about Poin is that it gets the loud type.
 *
 * Rows the API marked `needs_decider` say so beside the name. Nothing about
 * their numbers is wrong — the place itself is, because no criterion produced
 * it and the lot did. Given `onDecide`, saying so becomes a button; without one
 * (the public table) it stays a note.
 *
 * The `Adj` column appears only when some row carries a manual point
 * adjustment, and the reasons behind it are revealed per row rather than listed
 * under the table — six teams with three entries each is a wall of prose nobody
 * asked for. See AdjustmentCell. Given `onAdjust` the panel also offers the way
 * into the ledger; without one (the public table) it only explains.
 */
/** Max height of the reasons panel; drives the flip-up decision. */
const PANEL_MAX = 200;

type Coords = { right: number; top: number; bottom: number; flip: boolean };

export function StandingsTable({
  standings,
  highlight = 0,
  context = "goal",
  buchholz = false,
  onDecide,
  onAdjust,
  adjustment,
}: {
  standings: Standing[];
  highlight?: number;
  /** The table's shape: counts gol, game, or partai. */
  context?: StandingsContext;
  /**
   * Show the strength-of-schedule column. Follows the *engine*, not the sport —
   * only Swiss ranks on it — which is why it is a flag beside `context` rather
   * than a shape of its own.
   */
  buchholz?: boolean;
  /**
   * Offer to schedule the decider these rows are owed. Handed every team in the
   * deadlock — usually two, but three can be level all round — plus the group
   * whose table it settles.
   */
  onDecide?: (teams: MatchTeamRef[], group: string | null) => void;
  /**
   * Open the manual point ledger for this row. Its presence is what turns the
   * Adj cell into a button, so the public table simply omits it.
   */
  onAdjust?: (team: MatchTeamRef) => void;
  /**
   * Force the Adj column on. Derived from these rows when omitted, which is
   * right for one table — but a hybrid category is several tables side by side,
   * and deriving per group would print the column above one group and not the
   * next. GroupStandings answers it once for the whole category instead.
   */
  adjustment?: boolean;
}) {
  const columns = standingsColumns(context, {
    buchholz,
    adjustment: adjustment ?? hasAdjustments(standings),
  });
  const blocks = deadlocks(standings);

  if (standings.length === 0) {
    return (
      <p className="text-sm text-muted-foreground">
        Klasemen muncul setelah ada hasil pertandingan.
      </p>
    );
  }

  return (
    <>
      <div className="overflow-x-auto rounded-xl border border-border">
        <table className="w-full text-sm">
          <thead>
            <tr className="bg-[var(--surface-2)] text-xs uppercase tracking-wide text-muted-foreground">
              <th className="w-10 px-2 py-3 text-center font-semibold">#</th>
              <th className="px-3 py-3 text-left font-semibold">Tim</th>
              {columns.map((c) => (
                <th
                  key={c.key}
                  className={`whitespace-nowrap px-2 py-3 text-center font-semibold${
                    c.key === "points" ? " text-foreground" : ""
                  }`}
                >
                  {c.short}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {standings.map((s) => (
              <tr key={s.team.id} className="border-t border-border">
                <td
                  className="px-2 py-3 text-center font-mono text-muted-foreground"
                  style={
                    highlight > 0 && s.rank <= highlight
                      ? { boxShadow: "inset 3px 0 0 var(--success)", color: "var(--success)" }
                      : undefined
                  }
                >
                  {s.rank}
                </td>
                <td className="px-3 py-3 font-semibold">
                  <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
                    {s.team.name}
                    {s.needs_decider && (
                      <DeciderMark
                        onClick={
                          onDecide
                            ? () => onDecide(blocks.get(s.team.id) ?? [s.team], s.group_name)
                            : undefined
                        }
                      />
                    )}
                  </span>
                </td>
                {columns.map((c) => (
                  <td
                    key={c.key}
                    className={`whitespace-nowrap px-2 py-3 text-center${
                      c.key === "points" ? " font-extrabold" : ""
                    }`}
                    style={c.key === "points" ? { fontFamily: "var(--font-display)" } : undefined}
                  >
                    {c.key === "adjustment" ? (
                      <AdjustmentCell row={s} text={c.cell(s)} onAdjust={onAdjust} />
                    ) : (
                      c.cell(s)
                    )}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>

    </>
  );
}

/**
 * The Adj cell: the number, and on hover or tap the entries behind it.
 *
 * Revealed rather than always shown, because a table of six teams with three
 * entries each prints a wall of prose above the legend — but revealed
 * *somewhere*, because a "+2" nobody can account for is worse than no
 * adjustment at all, and the reader who most needs the account is the one on
 * the public table with no ledger to open.
 *
 * Portalled to <body> and positioned against the cell's viewport rect, the same
 * way TeamCombobox places its list and for the same reason: the table wrapper
 * is `overflow-x-auto`, and a single `auto` axis clips the other one too, so a
 * panel rendered inside the cell is cut off by the element holding it. It flips
 * above when the row sits near the bottom of the window, and hangs from the
 * right edge because Adj is the last column — growing rightwards would leave
 * the screen.
 *
 * Opens on hover *and* on click: hover alone is invisible on a phone, which is
 * where a spectator reads a public table.
 *
 * A row can carry a total with nothing behind it — a plain 0 in a table where
 * someone else was adjusted — and then there is nothing to explain.
 */
function AdjustmentCell({
  row,
  text,
  onAdjust,
}: {
  row: Standing;
  text: string;
  onAdjust?: (team: MatchTeamRef) => void;
}) {
  const notes = row.adjustment_notes ?? [];
  const [open, setOpen] = useState(false);
  const [coords, setCoords] = useState<Coords | null>(null);
  const anchorRef = useRef<HTMLSpanElement>(null);

  const place = useCallback(() => {
    const el = anchorRef.current;
    if (!el) return;
    const r = el.getBoundingClientRect();
    const below = window.innerHeight - r.bottom;
    setCoords({
      right: window.innerWidth - r.right,
      top: r.top,
      bottom: r.bottom,
      flip: below < PANEL_MAX && r.top > below,
    });
  }, []);

  useLayoutEffect(() => {
    if (!open) return;
    place();
    window.addEventListener("resize", place);
    // Capture phase, so scrolling the table or any ancestor repositions it too.
    window.addEventListener("scroll", place, true);
    return () => {
      window.removeEventListener("resize", place);
      window.removeEventListener("scroll", place, true);
    };
  }, [open, place]);

  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") setOpen(false);
    };
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [open]);

  if (notes.length === 0) {
    return onAdjust ? (
      <AdjustButton row={row} text={text} onAdjust={onAdjust} />
    ) : (
      <>{text}</>
    );
  }

  return (
    <span
      ref={anchorRef}
      className="relative inline-flex"
      onMouseEnter={() => setOpen(true)}
      onMouseLeave={() => setOpen(false)}
    >
      <button
        type="button"
        aria-label={`Alasan penyesuaian poin ${row.team.name}`}
        aria-expanded={open}
        onClick={() => setOpen((v) => !v)}
        onFocus={() => setOpen(true)}
        onBlur={() => setOpen(false)}
        className="underline decoration-dotted underline-offset-2 transition-opacity hover:opacity-70"
      >
        {text}
      </button>

      {open &&
        coords &&
        createPortal(
          <span
            role="tooltip"
            style={{
              position: "fixed",
              right: coords.right,
              maxHeight: PANEL_MAX,
              ...(coords.flip
                ? { bottom: window.innerHeight - coords.top + 6 }
                : { top: coords.bottom + 6 }),
            }}
            className="z-[60] w-64 overflow-y-auto rounded-lg border border-border bg-popover p-3 text-left text-xs font-normal leading-relaxed text-muted-foreground shadow-[var(--shadow-md)]"
          >
            <span className="block font-semibold text-foreground">
              Penyesuaian poin
            </span>
            <ul className="mt-1.5 grid gap-1">
              {notes.map((note, i) => (
                <li key={i} className="flex gap-2">
                  <span
                    className="shrink-0 font-semibold tabular-nums"
                    style={{ color: note.points > 0 ? "var(--success)" : "var(--danger)" }}
                  >
                    {note.points > 0 ? `+${note.points}` : note.points}
                  </span>
                  <span>{note.reason}</span>
                </li>
              ))}
            </ul>
            {onAdjust && (
              <button
                type="button"
                onClick={() => onAdjust(row.team)}
                className="mt-2 font-semibold text-[var(--brand-600)] transition-opacity hover:opacity-75"
              >
                Ubah penyesuaian
              </button>
            )}
          </span>,
          document.body,
        )}
    </span>
  );
}

/** The cell an organizer gets while there is nothing yet to explain. */
function AdjustButton({
  row,
  text,
  onAdjust,
}: {
  row: Standing;
  text: string;
  onAdjust: (team: MatchTeamRef) => void;
}) {
  return (
    <button
      type="button"
      onClick={() => onAdjust(row.team)}
      title="Lihat & ubah penyesuaian poin tim ini"
      className="underline decoration-dotted underline-offset-2 transition-opacity hover:opacity-70"
    >
      {text}
    </button>
  );
}

/**
 * Who is deadlocked with whom. The API flags rows, not pairs, so the grouping is
 * read back off the order it already ranked them in: flagged rows that sit next
 * to each other are the same deadlock, and a run of three is three teams no
 * criterion could tell apart.
 */
function deadlocks(standings: Standing[]): Map<string, MatchTeamRef[]> {
  const out = new Map<string, MatchTeamRef[]>();
  let run: Standing[] = [];

  const close = () => {
    if (run.length > 1) {
      const teams = run.map((r) => r.team);
      for (const r of run) out.set(r.team.id, teams);
    }
    run = [];
  };

  for (const s of standings) {
    if (s.needs_decider) run.push(s);
    else close();
  }
  close();

  return out;
}

/** ⚖ beside a name: this place came from the lot, not from a criterion. */
function DeciderMark({ onClick }: { onClick?: () => void }) {
  const label = "Butuh laga penentuan";

  if (!onClick) {
    return (
      <span className="badge badge-warning" title={label}>
        <Scale className="h-3 w-3" />
        {label}
      </span>
    );
  }

  return (
    <button
      type="button"
      onClick={onClick}
      title="Jadwalkan laga penentuan untuk memisahkan tim ini"
      className="badge badge-warning transition-opacity hover:opacity-80"
    >
      <Scale className="h-3 w-3" />
      {label}
    </button>
  );
}
