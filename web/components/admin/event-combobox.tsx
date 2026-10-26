"use client";

import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from "react";
import { createPortal } from "react-dom";
import { useQuery } from "@tanstack/react-query";
import { Check, ChevronDown, Search, X } from "lucide-react";

import { getAdminEvents } from "@/lib/api/admin-events";
import type { AdminEvent } from "@/types/api";

/**
 * Type-ahead event picker across every organization, backed by `/admin/events`.
 *
 * A plain <select> is not an option here: the list is platform-wide and grows
 * forever, so the search has to happen on the server (the same reasoning as
 * `TeamCombobox`, which this follows down to the portalled list and the
 * flip-up placement).
 *
 * Selection is `{id, name}`, not a bare id: the search is keyed by name, so the
 * closed-state label cannot be re-derived from the id alone without a second
 * request. The caller holds the pair so the chosen event stays readable while
 * the list is shut — and so a filter chip elsewhere on the page can name it.
 */
export interface EventOption {
  id: string;
  name: string;
  /** Shown under the name so two events with the same name stay distinguishable. */
  organization?: string;
}

const HOW_MANY = 20;
/** Max height of the results list; drives the flip-up decision. */
const LIST_MAX = 256; // 16rem

type Coords = { left: number; width: number; top: number; bottom: number; flip: boolean };

function toOption(event: AdminEvent): EventOption {
  return { id: event.id, name: event.name, organization: event.organization?.name };
}

export function EventCombobox({
  value,
  onChange,
  id,
  placeholder = "Semua event",
  disabled,
  className,
}: {
  /** Chosen event, or null for "no filter". */
  value: EventOption | null;
  onChange: (next: EventOption | null) => void;
  id?: string;
  placeholder?: string;
  disabled?: boolean;
  className?: string;
}) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");
  const [debounced, setDebounced] = useState("");
  const [coords, setCoords] = useState<Coords | null>(null);
  const anchorRef = useRef<HTMLDivElement>(null);
  const listRef = useRef<HTMLUListElement>(null);

  useEffect(() => {
    const t = setTimeout(() => setDebounced(query.trim()), 300);
    return () => clearTimeout(t);
  }, [query]);

  // The list is portalled to <body> so a card's overflow can't clip it, so it
  // has to be positioned by hand against the input's viewport rect — and flip
  // above when there isn't room below.
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
    // Capture phase so scrolling any ancestor repositions the list too.
    window.addEventListener("scroll", place, true);
    return () => {
      window.removeEventListener("resize", place);
      window.removeEventListener("scroll", place, true);
    };
  }, [open, place]);

  // Close on click-away / Escape. The list lives outside the anchor in the DOM
  // (portal), so both refs count as "inside".
  useEffect(() => {
    if (!open) return;
    const onDown = (e: MouseEvent) => {
      const t = e.target as Node;
      if (anchorRef.current?.contains(t) || listRef.current?.contains(t)) return;
      setOpen(false);
    };
    const onKey = (e: KeyboardEvent) => e.key === "Escape" && setOpen(false);
    document.addEventListener("mousedown", onDown);
    document.addEventListener("keydown", onKey);
    return () => {
      document.removeEventListener("mousedown", onDown);
      document.removeEventListener("keydown", onKey);
    };
  }, [open]);

  const results = useQuery({
    queryKey: ["admin-event-search", debounced],
    queryFn: () => getAdminEvents({ q: debounced || undefined, per_page: HOW_MANY }),
    enabled: open,
    // Matches feel instant on reopen without going stale mid-session.
    staleTime: 30_000,
  });

  const options = useMemo(() => (results.data?.items ?? []).map(toOption), [results.data]);

  const pick = (option: EventOption) => {
    onChange(option);
    setQuery("");
    setOpen(false);
  };

  const display = open ? query : (value?.name ?? "");

  return (
    <div ref={anchorRef} className={className}>
      <div className="relative min-w-0">
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
            // Typing over a selection abandons it until a new one is clicked,
            // so the figures on screen can never belong to an event whose name
            // is no longer in the box.
            if (value) onChange(null);
          }}
          className="flex h-10 w-full appearance-none truncate rounded-md border border-input bg-background py-2 pl-8 pr-9 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
        />
        {/* Clearing is the normal thing to do on a filter, so it gets a button
            of its own rather than making the admin select their way back out. */}
        {value && !disabled ? (
          <button
            type="button"
            aria-label="Hapus filter event"
            onClick={() => {
              onChange(null);
              setQuery("");
              setOpen(false);
            }}
            className="absolute right-1.5 top-1/2 grid h-7 w-7 -translate-y-1/2 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          >
            <X className="h-3.5 w-3.5" />
          </button>
        ) : (
          <ChevronDown className="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
        )}
      </div>

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
                {debounced ? "Tidak ada event yang cocok." : "Belum ada event."}
              </li>
            ) : (
              options.map((o) => (
                <li key={o.id} role="option" aria-selected={o.id === value?.id}>
                  <button
                    type="button"
                    // mousedown, not click: fires before the input's blur so the
                    // click-away handler doesn't close the list first.
                    onMouseDown={(e) => {
                      e.preventDefault();
                      pick(o);
                    }}
                    className="flex w-full items-start justify-between gap-2 px-3 py-2 text-left text-sm hover:bg-accent"
                  >
                    <span className="min-w-0">
                      <span className="block truncate">{o.name}</span>
                      {o.organization && (
                        <span className="block truncate text-xs text-muted-foreground">
                          {o.organization}
                        </span>
                      )}
                    </span>
                    {o.id === value?.id && (
                      <Check className="mt-0.5 h-4 w-4 shrink-0 text-[var(--brand-600)]" />
                    )}
                  </button>
                </li>
              ))
            )}
          </ul>,
          document.body
        )}
    </div>
  );
}
