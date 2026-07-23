"use client";

import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from "react";
import { createPortal } from "react-dom";
import { useQuery } from "@tanstack/react-query";
import { Check, ChevronDown, Search } from "lucide-react";

import { searchRegistrations } from "@/lib/api/events";
import type { Team, TeamStatus } from "@/types/api";

/**
 * Type-ahead team picker backed by server-side search. Unlike a plain <select>
 * it never renders every registration — the list a large tournament produces —
 * it queries the registrations endpoint as the organizer types (debounced) and
 * shows only the matches. Selection is by team id, same contract as the <select>
 * it replaces in the manual-match dialog.
 */
interface TeamComboboxProps {
  orgId: string;
  eventId: string;
  categoryId: string;
  /** Narrow to one group (hybrid); undefined = any group. */
  group?: string;
  /** Only this registration status is offered (manual matches pair approved teams). */
  status?: TeamStatus;
  /** Selected team id, or "" for none. Controlled by the parent. */
  value: string;
  onChange: (teamId: string) => void;
  /** Team already chosen on the other side — hidden so the same team can't play itself. */
  excludeId?: string;
  id?: string;
  placeholder?: string;
  disabled?: boolean;
}

const HOW_MANY = 8;
/** Max height of the results list; drives the flip-up decision. */
const LIST_MAX = 224; // 14rem

type Coords = { left: number; width: number; top: number; bottom: number; flip: boolean };

export function TeamCombobox({
  orgId,
  eventId,
  categoryId,
  group,
  status = "approved",
  value,
  onChange,
  excludeId,
  id,
  placeholder = "Cari tim…",
  disabled,
}: TeamComboboxProps) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");
  const [debounced, setDebounced] = useState("");
  // Remember the picked team so its name shows while the list is closed — the
  // server search is keyed by name, so we can't re-derive it from the id alone.
  const [selected, setSelected] = useState<Team | null>(null);
  const [coords, setCoords] = useState<Coords | null>(null);
  const anchorRef = useRef<HTMLDivElement>(null);
  const listRef = useRef<HTMLUListElement>(null);

  useEffect(() => {
    const t = setTimeout(() => setDebounced(query.trim()), 250);
    return () => clearTimeout(t);
  }, [query]);

  // The list is portalled to <body> so the dialog's overflow can't clip it, so
  // it must be positioned by hand against the input's viewport rect — and flip
  // above when there isn't room below (as for the lower "away" picker).
  const place = useCallback(() => {
    const el = anchorRef.current;
    if (!el) return;
    const r = el.getBoundingClientRect();
    const below = window.innerHeight - r.bottom;
    setCoords({ left: r.left, width: r.width, top: r.top, bottom: r.bottom, flip: below < LIST_MAX && r.top > below });
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

  // Close on click-away / tab-out. The list lives outside the anchor in the DOM
  // (portal), so both refs count as "inside".
  useEffect(() => {
    if (!open) return;
    const onDown = (e: MouseEvent) => {
      const t = e.target as Node;
      if (anchorRef.current?.contains(t) || listRef.current?.contains(t)) return;
      setOpen(false);
    };
    document.addEventListener("mousedown", onDown);
    return () => document.removeEventListener("mousedown", onDown);
  }, [open]);

  const results = useQuery({
    queryKey: ["team-search", orgId, eventId, categoryId, group ?? null, status, debounced],
    queryFn: () =>
      searchRegistrations(orgId, eventId, {
        search: debounced,
        categoryId,
        status,
        group,
        limit: HOW_MANY,
      }),
    enabled: open && !!orgId && !!categoryId,
    // Matches feel instant on reopen without going stale mid-session.
    staleTime: 30_000,
  });

  const options = useMemo(
    () => (results.data ?? []).filter((t) => t.id !== excludeId),
    [results.data, excludeId],
  );

  const pick = (team: Team) => {
    setSelected(team);
    onChange(team.id);
    setQuery("");
    setOpen(false);
  };

  // Derive the closed-state label instead of syncing it in an effect: if the
  // parent has cleared/changed the value (e.g. the group filter reset the pair),
  // the remembered team no longer matches and the field shows empty.
  const display = open ? query : selected && selected.id === value ? selected.name : "";

  return (
    <div ref={anchorRef} className="relative">
      <Search className="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
      <input
        id={id}
        type="text"
        role="combobox"
        aria-expanded={open}
        aria-controls={id ? `${id}-listbox` : undefined}
        autoComplete="off"
        disabled={disabled}
        value={display}
        placeholder={placeholder}
        onFocus={() => setOpen(true)}
        onChange={(e) => {
          setQuery(e.target.value);
          setOpen(true);
          // Typing over a selection abandons it until a new one is clicked.
          if (selected) {
            setSelected(null);
            onChange("");
          }
        }}
        className="flex h-10 w-full appearance-none rounded-md border border-input bg-background pl-8 pr-9 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
      />
      <ChevronDown className="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />

      {open &&
        coords &&
        createPortal(
          <ul
            ref={listRef}
            id={id ? `${id}-listbox` : undefined}
            role="listbox"
            style={{
              position: "fixed",
              left: coords.left,
              width: coords.width,
              maxHeight: LIST_MAX,
              ...(coords.flip
                ? { bottom: window.innerHeight - coords.top + 4 }
                : { top: coords.bottom + 4 }),
            }}
            className="z-[60] overflow-y-auto rounded-md border border-border bg-card py-1 shadow-[var(--shadow-lg)]"
          >
            {results.isLoading ? (
              <li className="px-3 py-2 text-sm text-muted-foreground">Memuat…</li>
            ) : options.length === 0 ? (
              <li className="px-3 py-2 text-sm text-muted-foreground">
                {debounced ? "Tidak ada tim yang cocok." : "Belum ada tim."}
              </li>
            ) : (
              options.map((t) => (
                <li key={t.id} role="option" aria-selected={t.id === value}>
                  <button
                    type="button"
                    // mousedown, not click: fires before the input's blur so the
                    // click-away handler doesn't close the list first.
                    onMouseDown={(e) => {
                      e.preventDefault();
                      pick(t);
                    }}
                    className="flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm hover:bg-accent"
                  >
                    <span className="min-w-0 truncate">
                      {t.name}
                      {t.group_name && !group && (
                        <span className="text-muted-foreground"> · Grup {t.group_name}</span>
                      )}
                    </span>
                    {t.id === value && <Check className="h-4 w-4 shrink-0 text-[var(--brand-600)]" />}
                  </button>
                </li>
              ))
            )}
          </ul>,
          document.body,
        )}
    </div>
  );
}
