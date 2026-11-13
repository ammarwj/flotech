"use client";

import {
  useCallback,
  useEffect,
  useId,
  useLayoutEffect,
  useMemo,
  useRef,
  useState,
} from "react";
import { createPortal } from "react-dom";
import { Check, ChevronDown, Search } from "lucide-react";

import { cn } from "@/lib/utils";

/** One pickable thing: a team in a real draw, a qualifier slot in a plan. */
export interface SeedOption {
  id: string;
  name: string;
}

/** Max height of the results list; drives the flip-up decision. */
const LIST_MAX = 224; // 14rem
/**
 * How many rows are rendered at once. A 200-team draw opened on a phone would
 * otherwise mount 200 buttons per side, twice per tie — the cap keeps the list
 * cheap and says so, rather than silently showing a prefix.
 */
const RENDER_CAP = 100;

type Coords = { left: number; width: number; top: number; bottom: number; flip: boolean };

/**
 * Type-ahead picker for one side of a bracket tie.
 *
 * Filtering happens **locally**, unlike `TeamCombobox`: the pool here is passed
 * in and is not always teams at all — the hybrid plan editor pairs qualifier
 * slots ("Juara Grup A"), which no endpoint can search. The pool is also already
 * in memory for the disabled-elsewhere check, so a request would re-fetch what
 * the caller holds.
 *
 * Already-placed entries stay **visible but disabled** rather than being hidden:
 * the organizer looking for a team needs to see that it is spoken for, and a
 * name that vanishes from the list reads as a team that vanished from the draw.
 */
export function SeedEntitySelect({
  id,
  value,
  onChange,
  pool,
  disabledIds,
  noteIds,
  noteLabel,
  emptyLabel,
  ariaLabel,
  placeholder = "Cari…",
  className,
}: {
  /** For a caller whose <Label htmlFor> points at this field. */
  id?: string;
  /** Chosen id, or null for the empty side. */
  value: string | null;
  onChange: (id: string | null) => void;
  pool: SeedOption[];
  /** Ids placed in another tie (or on the other side of this one). */
  disabledIds: Set<string>;
  /**
   * Ids that stay pickable but carry a note — the slot editor, where choosing a
   * team that already has a slot *exchanges* the two rather than being refused.
   * Separate from `disabledIds` because the two answer different questions and
   * folding them into one set would make a legal move look forbidden.
   */
  noteIds?: Set<string>;
  /** What that note says, e.g. "di slot lain". Required alongside `noteIds`. */
  noteLabel?: string;
  /** What picking nothing means here — "—" for the home side, "Bye" for away. */
  emptyLabel: string;
  ariaLabel: string;
  placeholder?: string;
  className?: string;
}) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");
  const [active, setActive] = useState(0);
  const [coords, setCoords] = useState<Coords | null>(null);
  const anchorRef = useRef<HTMLDivElement>(null);
  const listRef = useRef<HTMLUListElement>(null);
  /* The list is portalled, so `aria-controls` is the only thing tying it back
     to this input for a screen reader. */
  const listId = `${useId()}-listbox`;

  // The list is portalled to <body> so the dialog's own overflow can't clip it,
  // so it must be positioned by hand against the input's viewport rect — and
  // flip above when there isn't room below (the lower ties in a long draw).
  const place = useCallback(() => {
    const el = anchorRef.current;
    if (!el) return;
    const r = el.getBoundingClientRect();
    const below = window.innerHeight - r.bottom;
    setCoords({
      left: r.left,
      width: r.width,
      top: r.top,
      bottom: r.bottom,
      flip: below < LIST_MAX && r.top > below,
    });
  }, []);

  useLayoutEffect(() => {
    if (!open) return;
    place();
    window.addEventListener("resize", place);
    // Capture phase so scrolling any ancestor (the dialog body) repositions too.
    window.addEventListener("scroll", place, true);
    return () => {
      window.removeEventListener("resize", place);
      window.removeEventListener("scroll", place, true);
    };
  }, [open, place]);

  // Close on click-away. The list lives outside the anchor in the DOM (portal),
  // so both refs count as "inside".
  useEffect(() => {
    if (!open) return;
    const onDown = (e: MouseEvent) => {
      const t = e.target as Node;
      if (anchorRef.current?.contains(t) || listRef.current?.contains(t)) return;
      setOpen(false);
      setQuery("");
    };
    document.addEventListener("mousedown", onDown);
    return () => document.removeEventListener("mousedown", onDown);
  }, [open]);

  const matches = useMemo(() => {
    const q = query.trim().toLowerCase();
    return q ? pool.filter((o) => o.name.toLowerCase().includes(q)) : pool;
  }, [pool, query]);

  /**
   * The empty row leads the list so clearing a side never needs scrolling, and
   * it is part of the same array the arrow keys walk — a second code path for
   * "the one option that isn't in the pool" is how one of them stops working.
   */
  const rows = useMemo<
    { id: string | null; name: string; disabled: boolean; note?: string }[]
  >(
    () => [
      { id: null, name: emptyLabel, disabled: false },
      ...matches.slice(0, RENDER_CAP).map((o) => ({
        id: o.id,
        name: o.name,
        disabled: disabledIds.has(o.id),
        note: noteIds?.has(o.id) ? noteLabel : undefined,
      })),
    ],
    [matches, disabledIds, noteIds, noteLabel, emptyLabel]
  );

  const truncated = matches.length > RENDER_CAP;
  const selected = value ? pool.find((o) => o.id === value) : null;
  const display = open ? query : (selected?.name ?? "");

  const pick = (id: string | null) => {
    onChange(id);
    setQuery("");
    setOpen(false);
  };

  // Walks past disabled rows, so Enter can never land on a name the backend
  // would refuse.
  const step = (from: number, dir: 1 | -1) => {
    for (let i = from + dir; i >= 0 && i < rows.length; i += dir) {
      if (!rows[i].disabled) return i;
    }
    return from;
  };

  const onKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === "Escape") {
      setOpen(false);
      setQuery("");
      return;
    }
    if (e.key === "ArrowDown" || e.key === "ArrowUp") {
      e.preventDefault();
      if (!open) {
        setOpen(true);
        return;
      }
      setActive((a) => step(a, e.key === "ArrowDown" ? 1 : -1));
      return;
    }
    if (e.key === "Enter" && open) {
      const row = rows[active];
      if (row && !row.disabled) {
        e.preventDefault();
        pick(row.id);
      }
    }
  };

  return (
    <div ref={anchorRef} className={cn("relative min-w-0", className)}>
      <Search className="pointer-events-none absolute left-2 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
      <input
        id={id}
        type="text"
        role="combobox"
        aria-expanded={open}
        aria-controls={listId}
        aria-label={ariaLabel}
        autoComplete="off"
        value={display}
        placeholder={selected ? selected.name : placeholder}
        onFocus={() => {
          setOpen(true);
          setActive(0);
        }}
        onKeyDown={onKeyDown}
        onChange={(e) => {
          setQuery(e.target.value);
          setOpen(true);
          setActive(0);
        }}
        className="flex h-9 w-full appearance-none truncate rounded-md border border-input bg-background py-2 pl-7 pr-7 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
      />
      <ChevronDown className="pointer-events-none absolute right-2 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />

      {open &&
        coords &&
        createPortal(
          <ul
            ref={listRef}
            id={listId}
            role="listbox"
            aria-label={ariaLabel}
            style={{
              position: "fixed",
              left: coords.left,
              width: Math.max(coords.width, 180),
              maxHeight: LIST_MAX,
              ...(coords.flip
                ? { bottom: window.innerHeight - coords.top + 4 }
                : { top: coords.bottom + 4 }),
            }}
            className="z-[60] overflow-y-auto rounded-md border border-border bg-card py-1 shadow-[var(--shadow-lg)]"
          >
            {rows.length === 1 && query.trim() ? (
              <li className="px-3 py-2 text-xs text-muted-foreground">Tidak ada yang cocok.</li>
            ) : null}
            {rows.map((row, i) => (
              <li key={row.id ?? "__empty"} role="option" aria-selected={row.id === value}>
                <button
                  type="button"
                  disabled={row.disabled}
                  // mousedown, not click: fires before the input's blur so the
                  // click-away handler doesn't close the list first.
                  onMouseDown={(e) => {
                    e.preventDefault();
                    pick(row.id);
                  }}
                  onMouseEnter={() => setActive(i)}
                  className={cn(
                    "flex w-full items-center justify-between gap-2 px-3 py-1.5 text-left text-sm",
                    row.id === null && "text-muted-foreground",
                    row.disabled
                      ? "cursor-not-allowed text-muted-foreground/50"
                      : "hover:bg-accent",
                    i === active && !row.disabled && "bg-accent"
                  )}
                >
                  <span className="min-w-0 truncate">{row.name}</span>
                  {row.disabled ? (
                    <span className="shrink-0 text-[0.65rem] uppercase tracking-wide">
                      Terpakai
                    </span>
                  ) : row.note ? (
                    <span className="shrink-0 text-[0.65rem] text-muted-foreground">
                      {row.note}
                    </span>
                  ) : (
                    row.id === value && (
                      <Check className="h-3.5 w-3.5 shrink-0 text-[var(--brand-600)]" />
                    )
                  )}
                </button>
              </li>
            ))}
            {truncated && (
              <li className="border-t border-border px-3 py-1.5 text-[0.7rem] text-muted-foreground">
                {matches.length - RENDER_CAP} lainnya — ketik untuk menyaring.
              </li>
            )}
          </ul>,
          document.body
        )}
    </div>
  );
}
