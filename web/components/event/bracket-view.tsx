import React from "react";
import { Pencil } from "lucide-react";

import {
  THIRD_PLACE_LABEL,
  knockoutRoundLabel,
  matchNumberLabel,
  matchWinnerId,
  crestGradient,
  groupByRound,
  isBye,
  isDeadSide,
  isThirdPlace,
  sideLabel,
  wentToPenalties,
} from "@/lib/bracket";
import { cn } from "@/lib/utils";
import { matchScoreText } from "@/lib/scoring";
import type { Match } from "@/types/api";

function Side({
  name,
  pending,
  logoUrl,
  score,
  penalty,
  isWinner,
  decided,
}: {
  name: string | null;
  /**
   * The side holds no team yet, so `name` is a caption rather than a name —
   * "Pemenang P5", "Bye", "TBD". Passed in rather than re-derived from `name`,
   * because a caption and a club called "Bye" are not the same thing.
   */
  pending?: boolean;
  logoUrl?: string | null;
  score: number | null;
  /** Shootout score, shown next to the scoreline when the tie went to penalties. */
  penalty?: number | null;
  isWinner: boolean;
  decided: boolean;
}) {
  return (
    <div className={cn("bkt-side", pending && "bkt-tbd", decided && (isWinner ? "win" : "lose"))}>
      {logoUrl ? (
        // eslint-disable-next-line @next/next/no-img-element
        <img className="bkt-crest" src={logoUrl} alt={name ?? ""} style={{ objectFit: "cover" }} />
      ) : (
        <span
          className="bkt-crest"
          style={{ background: name && !pending ? crestGradient(name) : "var(--border)" }}
        />
      )}
      <span className="bkt-name">{name ?? "TBD"}</span>
      {penalty !== null && penalty !== undefined && (
        <span className="bkt-pen" title="Adu penalti">
          ({penalty})
        </span>
      )}
      {score !== null && <span className="bkt-score">{score}</span>}
    </div>
  );
}

/**
 * One tie of the draw. Lives outside BracketView because the third-place
 * play-off under the final shows the same card — scoreline, bye, shootout
 * and all.
 */
function MatchCell({
  match: m,
  onEditSlot,
}: {
  match: Match;
  /** Renders the seed-correction affordance. Omitted where a slot is fed. */
  onEditSlot?: (match: Match) => void;
}) {
  const winner = matchWinnerId(m);
  const decided = winner !== null;
  const pens = wentToPenalties(m);
  // What a tie is called before it has teams. Printed above the card rather
  // than inside a side, because it names the fixture, not either half of it —
  // and it is the whole point: an organizer writing the programme has to be
  // able to refer to a slot that still says "Pemenang P5" twice.
  const number = matchNumberLabel(m);
  // Set/partai detail under the two sides — "21-15, 21-18" for badminton, the
  // played rubbers for a squad tie, undefined (nothing) for goal sports.
  const detail = matchScoreText(m).detail;

  return (
    <div className="bkt-match">
      {onEditSlot && (
        <button
          type="button"
          onClick={() => onEditSlot(m)}
          aria-label="Ganti tim di slot ini"
          title="Ganti tim"
          className="absolute -right-2 -top-2 z-10 grid h-6 w-6 place-items-center rounded-md border border-border bg-card text-muted-foreground opacity-0 shadow-[var(--shadow-sm)] transition-opacity hover:text-foreground focus-visible:opacity-100 group-hover/slot:opacity-100"
        >
          <Pencil className="h-3 w-3" />
        </button>
      )}
      {number && <div className="bkt-no">{number}</div>}
      {/* A side nothing can ever arrive on is left off: on a chart that branch
          runs straight through, and printing "Bye" opposite a placeholder pairs
          a real slot against a word. */}
      {!isDeadSide(m, "home") && (
      <Side
        name={sideLabel(m, "home")}
        pending={!m.home_team}
        logoUrl={m.home_team?.logo_url}
        score={m.home_score}
        penalty={pens ? m.home_penalty : null}
        isWinner={winner === m.home_team_id}
        decided={decided}
      />
      )}
      {!isDeadSide(m, "away") && (
      <Side
        name={sideLabel(m, "away")}
        pending={!m.away_team}
        logoUrl={m.away_team?.logo_url}
        score={m.away_score}
        penalty={pens ? m.away_penalty : null}
        isWinner={winner === m.away_team_id}
        decided={decided}
      />
      )}
      {detail && <div className="bkt-sets">{detail}</div>}
    </div>
  );
}

/** Single-elimination bracket: one column per round, joined by connector lines. */
export function BracketView({
  matches,
  roundLabel,
  onEditSlot,
}: {
  matches: Match[];
  /** Override the per-column heading. Defaults to single-elim round names. */
  roundLabel?: (matchesInRound: number, round: number) => string;
  /**
   * Organizer-only: correct a seed. Offered on the first round only, because
   * later slots belong to advanceWinner() — a team put there by hand would be
   * overwritten the moment its feeder is confirmed.
   */
  onEditSlot?: (match: Match) => void;
}) {
  /**
   * The length of each pair's vertical, measured after layout.
   *
   * It is the gap between a card's mid-line and its partner's, and CSS cannot
   * state it: the cell's own height is the same number only while its two
   * halves are equally tall, and a collapsed bye row makes sure they are not.
   * Left as `height: 100%` the line ran 106px past the card it was meant to
   * reach and stopped in open space — the "garis belum nyambung" on screen.
   *
   * Measured rather than derived because the rows are sized by the browser from
   * card heights it alone knows: a name that wraps onto two lines changes the
   * answer, and so does a resize. Hence the observer.
   */
  const bktRef = React.useRef<HTMLDivElement>(null);

  React.useEffect(() => {
    const root = bktRef.current;
    if (!root) return;

    const measure = () => {
      for (const cell of root.querySelectorAll<HTMLElement>(".bkt-joins")) {
        // Which cell this line has to reach is the component's answer, not the
        // DOM's: with one grid for the whole bracket every cell is a sibling of
        // every other, so `nextElementSibling` wanders into the next round.
        const partner = root.querySelector<HTMLElement>(
          `[data-pair="${cell.dataset.cell}"]`,
        );
        if (!partner) continue;

        // Measured between the two *cells'* mid-lines, because that is where
        // the stubs are painted (`top: 50%` of the cell). Measuring between the
        // cards instead looks equivalent and is not: a card is centred in its
        // cell only to within a pixel of rounding, and that pixel showed up as
        // a visible nick where the vertical met the stub.
        const a = cell.getBoundingClientRect();
        const b = partner.getBoundingClientRect();
        const drop = b.top + b.height / 2 - (a.top + a.height / 2);

        cell.style.setProperty("--bkt-drop", `${Math.max(0, drop)}px`);
      }
    };

    measure();

    const ro = new ResizeObserver(measure);
    ro.observe(root);
    for (const card of root.querySelectorAll(".bkt-match")) ro.observe(card);

    return () => ro.disconnect();
  });

  // The third-place tie shares the final's round but is not a slot of the tree —
  // giving the final column a second cell would halve the column's slots and
  // pull the final off the mid-line the semifinal connectors meet at. It hangs
  // off the final's own cell instead (see .bkt-cell-third).
  const rounds = groupByRound(matches.filter((m) => !isThirdPlace(m)));
  if (rounds.length === 0) return null;

  // The backend never creates one for a bracket of two, so there is always a
  // final above it to hang it under.
  const third = matches.filter(isThirdPlace);

  // Hand-added friendlies share round 1 with a single-elimination bracket, but
  // their order keeps counting past it — they are not slots and the backend
  // refuses to re-seat them.
  const maxRound = rounds[rounds.length - 1][0];
  const slotCount = 2 ** Math.max(1, maxRound) / 2;

  // Every column is laid out on the *same* row grid — the opening round's slot
  // count — and each card spans the rows its feeders occupy. That is what puts
  // a card on the midpoint of the pair below it.
  //
  // Giving each column its own equal division instead is exact only while every
  // round halves cleanly. A ragged draw never does: nine ties do not divide
  // into five, so each card drifts off the pair feeding it and the error
  // compounds rightward — measured at 278px by the fourth round, with the
  // connector entering a card well above the card itself. On a power-of-two
  // bracket the two are the same thing, since spanning 2^col of 2^n rows *is*
  // an equal division.
  const baseRows = rounds[0][1].length;

  /**
   * A bye is not drawn, because it is not a tie.
   *
   * Nobody played it and the team that got it is already seated in the slot
   * above, so a printed bracket shows that team once, in the round it actually
   * enters, with its branch simply starting further right. Drawing a card
   * instead puts a fixture on the chart that never happened and pairs a real
   * school against the word "Bye" — twenty-eight times over in a field of 36.
   *
   * The *cell* stays: it owns rows in the shared grid, and taking those away
   * would move every card above it off the line that feeds it.
   */
  const hidden = (m: Match) => isBye(m);

  /**
   * The row band a cell of `col` at index `i` covers, 1-based and
   * inclusive-exclusive.
   *
   * Walks back down the tree: a cell is fed by 2k and 2k+1 of the round below,
   * clamped at that round's width — the odd cell of a ragged round has only one
   * feeder, and must sit on that one rather than on a phantom pair.
   */
  const rowSpan = (col: number, i: number): [number, number] => {
    let start = i;
    let end = i + 1;
    for (let c = col; c > 0; c--) {
      const below = rounds[c - 1][1].length;
      start = Math.min(start * 2, below - 1);
      end = Math.min(end * 2, below);
    }
    return [start + 1, end + 1];
  };

  /**
   * The rows a cell should actually occupy, so that centring its card puts it
   * exactly where the lines feeding it meet.
   *
   * Reading note: a feeder's card is centred in *its* span, and this span runs
   * from that card's row to the last feeder's. Both ends therefore land on a
   * row that holds a card, and the midpoint of the span is the midpoint of
   * those two cards — which is what the connector aims at.
   *
   * Not the full band. A card sits at the middle of the rows it spans, so the
   * span has to run from *where the first feeder's card is drawn* to *where the
   * last one is* — anything beyond that at either end is dead weight pulling
   * the card off their midpoint. In a padded draw there is plenty of it: the
   * final's band is all thirty-two rows while the two semifinals feeding it are
   * drawn in two of them.
   *
   * Derived recursively, because a feeder is itself centred in its own trimmed
   * span: the row a card occupies is the middle of that span, and a round-one
   * card simply occupies its own row.
   *
   * This is what hands alignment to the grid, which is the one thing measuring
   * every column identically. The arithmetic it replaces — rows above versus
   * rows below, converted to a percentage — got within 20px and could not do
   * better, because it reasoned about rows while the browser reasoned about
   * pixels.
   */
  const spanCache = new Map<string, [number, number]>();

  const drawnSpan = (col: number, i: number): [number, number] => {
    const key = `${col}:${i}`;
    const hit = spanCache.get(key);
    if (hit) return hit;

    let span: [number, number];

    if (col === 0) {
      span = rowSpan(col, i);
    } else {
      const below = rounds[col - 1][1];
      // The union of the feeders' own spans. Byes are skipped — they draw
      // nothing, so nothing meets there.
      //
      // The union, not "the row each feeder's card sits in": a feeder is itself
      // centred in a span of several rows, and naming one of them meant
      // rounding to a whole row. That rounding is what left the card 6px off,
      // and up to 59px where a round mixed one-line names with two-line ones.
      // Taking the union keeps both ends on the same boundaries the feeders
      // use, so the midpoints coincide exactly however tall the rows came out.
      const spans = [2 * i, 2 * i + 1]
        .filter((k) => k < below.length && !hidden(below[k]))
        .map((k) => drawnSpan(col - 1, k));

      span = spans.length
        ? [Math.min(...spans.map(([a]) => a)), Math.max(...spans.map(([, b]) => b))]
        : rowSpan(col, i);
    }

    spanCache.set(key, span);
    return span;
  };

  /**
   * The row track list, one entry per row of the shared grid.
   *
   * Not `repeat(n, 1fr)`: equal rows are sized by the tallest *card* in the
   * tree, and in a padded draw most rows hold a bye, which draws nothing. A
   * 36-team bracket has four real ties among thirty-two opening rows, so equal
   * rows made the column 114px × 32 = 3648px tall to show those four — sized
   * entirely by what it was not drawing.
   *
   * So a row earns `1fr` only when some column actually puts a card in it;
   * every other row is `auto`, which for an empty cell is nothing at all. The
   * grid stays the same height everywhere, which is what keeps a card on the
   * midpoint of the ties feeding it.
   *
   * A row counts as carrying a card when a cell *starts* there: a later-round
   * cell spans many rows but hangs its card at one point inside that span, and
   * giving all sixteen of a semifinal's rows an fr share would restore exactly
   * the stretch this avoids.
   */
  const carries = new Set<number>();
  for (const [col, [, list]] of rounds.entries()) {
    list.forEach((m, i) => {
      if (!hidden(m)) carries.add(rowSpan(col, i)[0]);
    });
  }

  const rowTracks = Array.from({ length: baseRows }, (_, i) =>
    carries.has(i + 1) ? "minmax(64px, 1fr)" : "auto",
  ).join(" ");



  return (
    <div
      ref={bktRef}
      className="bkt"
      style={
        { "--bkt-rows": rowTracks } as React.CSSProperties
      }
    >
      {rounds.map(([round, list], col) => (
        // A fragment, not a wrapper: one grid holds every cell of the bracket,
        // and an element around a round would be a grid item of its own — the
        // very per-column nesting that stopped the rows lining up.
        <React.Fragment key={round}>
          <div className="bkt-head" style={{ gridColumn: col + 1 }}>
            {roundLabel
              ? roundLabel(list.length, round)
              : // Teams still in = winners of the round below, i.e. its tie
                // count. "N Besar" from list.length × 2 assumes every tie has
                // two sides, which a ragged draw breaks: five ties hold nine
                // teams, not ten.
                knockoutRoundLabel(list.length, rounds[col - 1]?.[1].length)}
          </div>
          {list.map((m, i) => {
            // The backend refuses a slot whose result is already in, so the
            // affordance must not appear there either.
            const editable =
              onEditSlot &&
              m.round === 1 &&
              m.order < slotCount &&
              m.home_score === null &&
              m.away_score === null;
            // Under the last cell of the last column — the final.
            const withThird =
              third.length > 0 && col === rounds.length - 1 && i === list.length - 1;
            const [from, to] = drawnSpan(col, i);
            // A bye keeps its rows — they hold the grid every column is
            // measured against — but draws neither card nor connector.
            const blank = hidden(m);
            // What this cell draws of the tree. `feeds` is the stub out of its
            // right-hand side, `joins` the vertical down to the other half of
            // its pair, `fed` the lead-in from the round below. Decided here
            // rather than by CSS structure selectors: one grid has no element
            // per round for `:not(:last-child)` to match, and `nth-child(odd)`
            // would count across every round at once.
            const parentCol = rounds[col + 1];
            const sibling = i % 2 === 0 ? list[i + 1] : list[i - 1];
            // The stub only where the card it runs to is on the chart. A
            // parent whose feeders were all byes carries its teams already and
            // draws no lead-in, so a stub pointing at it is a line leaving a
            // card and stopping in blank space — which reads on screen as the
            // two cards *failing* to join, since the next column's card is
            // sitting right there beside it.
            //
            // The mirror of `fed` below, and it has to be: one of them drawing
            // without the other is exactly half an elbow.
            const parent = parentCol?.[1][Math.floor(i / 2)];
            const parentDrawn =
              !!parent &&
              !hidden(parent) &&
              [i - (i % 2), i - (i % 2) + 1].some((k) => {
                const f = list[k];
                return !!f && !hidden(f);
              });
            const feeds = !blank && parentDrawn;
            const joins = feeds && i % 2 === 0 && !!sibling && !hidden(sibling);
            // The only half of its pair that is drawn, so no vertical joins it
            // to anything: its line runs straight across to the parent instead.
            // Without this the stub stopped 18px out and the parent's lead-in
            // began 38px further on, leaving the gap between two columns
            // completely unpainted — two cards side by side with nothing
            // between them, which is exactly how it read on screen.
            const alone = feeds && (!sibling || hidden(sibling));
            // A lead-in only where something is drawn to lead in *from*. A card
            // whose feeders were all byes is the start of its branch, however
            // far right it sits — both its ties walked over, so there is no
            // line coming out of the round below and a stub reaching back into
            // that column ends in blank space.
            const fedBy =
              col > 0 &&
              [2 * i, 2 * i + 1].some((k) => {
                const feeder = rounds[col - 1][1][k];
                return !!feeder && !hidden(feeder);
              });
            const fed = !blank && fedBy;

            return (
              <div
                key={m.id}
                className={cn(
                  "bkt-cell group/slot",
                  withThird && "bkt-cell-third",
                  blank && "bkt-cell-blank",
                  feeds && "bkt-feeds",
                  alone && "bkt-solo",
                  joins && "bkt-joins",
                  fed && "bkt-fed",
                )}
                // Who this cell is, and whose vertical ends on it. Named here
                // because the DOM can no longer answer it: one grid holds every
                // round, so every cell is a sibling of every other and
                // `nextElementSibling` crosses into the next column.
                //
                // `data-pair` is worn by the *lower* half of a pair and carries
                // the upper half's id — which is what the upper half looks up
                // to find how far its line must drop.
                data-cell={`${col}:${i}`}
                data-pair={i % 2 === 1 ? `${col}:${i - 1}` : undefined}
                style={
                  {
                    gridColumn: col + 1,
                    // +1 for the heading row at the top of the grid.
                    gridRow: `${from + 1} / ${to + 1}`,
                  } as React.CSSProperties
                }
              >
                {!blank && (
                  <MatchCell match={m} onEditSlot={editable ? onEditSlot : undefined} />
                )}
                {withThird && (
                  // No onEditSlot: both slots are filled by advanceLoser(), so
                  // a team put here by hand would be overwritten the moment a
                  // semifinal is confirmed.
                  <div className="bkt-third">
                    {/* Shares .bkt-head so the heading never drifts from the
                        column labels above it. */}
                    <div className="bkt-head bkt-third-head">{THIRD_PLACE_LABEL}</div>
                    {third.map((t) => (
                      <div key={t.id} className="bkt-third-card">
                        <MatchCell match={t} />
                      </div>
                    ))}
                  </div>
                )}
              </div>
            );
          })}
        </React.Fragment>
      ))}
    </div>
  );
}
