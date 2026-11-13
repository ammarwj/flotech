"use client";

import * as React from "react";
import { createPortal } from "react-dom";
import {
  addMonths,
  eachDayOfInterval,
  endOfMonth,
  endOfWeek,
  format,
  isSameDay,
  isSameMonth,
  isToday,
  parseISO,
  setMonth,
  setYear,
  startOfMonth,
  startOfWeek,
  subMonths,
} from "date-fns";
import { id as idLocale } from "date-fns/locale/id";
import { Calendar, ChevronDown, ChevronLeft, ChevronRight, X } from "lucide-react";

import { cn } from "@/lib/utils";

const WEEKDAYS = ["Min", "Sen", "Sel", "Rab", "Kam", "Jum", "Sab"];
/** Canonical value format shared with the API (`YYYY-MM-DD`). */
const ISO = "yyyy-MM-dd";
const MONTHS = Array.from({ length: 12 }, (_, m) =>
  format(new Date(2000, m, 1), "MMMM", { locale: idLocale })
);
/**
 * How far back the year dropdown reaches. Custom registration fields ask for
 * birth dates, so the chevrons alone would mean ~430 clicks to reach 1990.
 */
const YEARS_BACK = 100;
const YEARS_AHEAD = 10;

/**
 * The panel's own width (`w-76` = 19rem), in px, and the breathing room kept
 * from the viewport edge. Spelled here because the flip decision is made in JS
 * before the panel exists to be measured.
 */
const PANEL_WIDTH = 304;
const VIEWPORT_MARGIN = 8;
/**
 * Roughly how tall the panel gets (header + weekday row + six day rows), in px.
 * Spelled here for the same reason as the width: the flip decision is made
 * before the panel exists to be measured. An over-estimate is the safe
 * direction — it flips up a little eagerly rather than opening a calendar whose
 * last week is below the fold.
 */
const PANEL_HEIGHT = 330;

/**
 * Month/year jump in the calendar header. A bare styled <select> reads as a
 * plain title — nobody clicks a heading — so it's dressed as a pill with a
 * caret. The native element stays underneath (invisible, stretched over the
 * pill) to keep the mobile wheel picker and keyboard behaviour for free.
 */
function HeaderSelect({
  label,
  value,
  onChange,
  options,
}: {
  label: string;
  value: number;
  onChange: (value: number) => void;
  options: { value: number; label: string }[];
}) {
  return (
    <div className="relative inline-flex items-center gap-1 rounded-md border border-border bg-background py-1 pl-2 pr-1.5 text-sm font-semibold transition-colors hover:border-primary hover:bg-accent focus-within:ring-2 focus-within:ring-ring">
      <span>{options.find((o) => o.value === value)?.label}</span>
      <ChevronDown className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
      <select
        aria-label={label}
        value={value}
        onChange={(e) => onChange(Number(e.target.value))}
        className="absolute inset-0 cursor-pointer opacity-0 focus-visible:outline-none"
      >
        {options.map((o) => (
          <option key={o.value} value={o.value}>
            {o.label}
          </option>
        ))}
      </select>
    </div>
  );
}

/**
 * Calendar date picker that reads/writes plain `YYYY-MM-DD` strings, so it's a
 * drop-in replacement for `<input type="date">`. Built on date-fns to avoid
 * pulling in a calendar/popover dependency (same philosophy as `select.tsx`).
 */
export function DatePicker({
  id,
  value,
  onChange,
  min,
  max,
  placeholder = "Pilih tanggal",
  clearable,
  disabled,
  className,
  "aria-label": ariaLabel,
  "aria-invalid": ariaInvalid,
}: {
  id?: string;
  /** Selected date as `YYYY-MM-DD`, or empty when none. */
  value?: string;
  onChange: (value: string) => void;
  /** Earliest selectable date as `YYYY-MM-DD`; earlier days are disabled. */
  min?: string;
  /** Latest selectable date as `YYYY-MM-DD`; later days are disabled. */
  max?: string;
  placeholder?: string;
  /**
   * Show a clear button while a date is picked.
   *
   * Off by default: on a form, emptying a date field is usually invalid, and a
   * filter is the case where it is the normal thing to do.
   */
  clearable?: boolean;
  disabled?: boolean;
  className?: string;
  /** For a picker with no <label> of its own — half of a range, say. */
  "aria-label"?: string;
  "aria-invalid"?: boolean;
}) {
  const [open, setOpen] = React.useState(false);
  const selected = value ? parseISO(value) : null;

  /**
   * Which month to show when there is no selection: the nearest end of the
   * allowed window, or today when it falls inside it. Opening on today for an
   * event that ran last November would make the picker look empty — every day
   * on screen struck through — until the viewer found the chevrons.
   */
  const openingMonth = React.useCallback(() => {
    if (selected) return startOfMonth(selected);
    const today = format(new Date(), ISO);
    if (min && today < min) return startOfMonth(parseISO(min));
    if (max && today > max) return startOfMonth(parseISO(max));
    return startOfMonth(new Date());
  }, [selected, min, max]);

  const [viewMonth, setViewMonth] = React.useState<Date>(openingMonth);
  const rootRef = React.useRef<HTMLDivElement>(null);
  const panelRef = React.useRef<HTMLDivElement>(null);

  /**
   * Where the panel sits, in viewport coordinates.
   *
   * It is portalled to `<body>` rather than left beside the trigger, because
   * `position: absolute` is clipped by any scrolling ancestor — and this field
   * lives inside dialogs whose body is `overflow-y-auto`, where the calendar
   * was cut off mid-month. Portalling means it must be placed by hand (the same
   * trade `TeamCombobox` makes).
   *
   * `left` is clamped rather than flipped to an edge: once the panel is wider
   * than the space on *both* sides of the trigger — a 320px phone with a field
   * that is neither flush left nor flush right — flipping only chooses which
   * edge overhangs. Clamping slides it along the viewport instead.
   */
  const [coords, setCoords] = React.useState<{
    left: number;
    top: number;
    bottom: number;
    flip: boolean;
  } | null>(null);

  const toggle = () => {
    // Jump the calendar to the selected month right before opening.
    if (!open) setViewMonth(openingMonth());
    setOpen((o) => !o);
  };

  // Measured before paint, so the panel never renders in the wrong place first.
  React.useLayoutEffect(() => {
    if (!open || !rootRef.current) return;

    const place = () => {
      const rect = rootRef.current?.getBoundingClientRect();
      if (!rect) return;
      // Preferred position is flush with the trigger; the clamp pulls it back
      // inside whichever margin it would have crossed. `max` is applied last
      // so that a panel too wide for the viewport overhangs on the right,
      // where the calendar has already been read, not on the left.
      const maxLeft = window.innerWidth - VIEWPORT_MARGIN - PANEL_WIDTH;
      const below = window.innerHeight - rect.bottom;
      setCoords({
        left: Math.max(VIEWPORT_MARGIN, Math.min(rect.left, maxLeft)),
        top: rect.top,
        bottom: rect.bottom,
        // Only flip when there is genuinely more room above: a short viewport
        // fits the panel nowhere, and flipping there just moves the clipping
        // from the bottom edge to the top one.
        flip: below < PANEL_HEIGHT && rect.top > below,
      });
    };

    place();
    window.addEventListener("resize", place);
    // Capture phase, so scrolling any ancestor (a dialog body) moves the panel
    // with its trigger instead of leaving it stranded mid-screen.
    window.addEventListener("scroll", place, true);
    return () => {
      window.removeEventListener("resize", place);
      window.removeEventListener("scroll", place, true);
    };
  }, [open]);

  // Close on outside click / Escape. The panel lives outside the trigger in the
  // DOM now (portal), so both refs have to count as "inside" — without the
  // second one, picking a month from the header would close the calendar.
  React.useEffect(() => {
    if (!open) return;
    const onDown = (e: MouseEvent) => {
      const t = e.target as Node;
      if (rootRef.current?.contains(t) || panelRef.current?.contains(t)) return;
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

  const days = eachDayOfInterval({
    start: startOfWeek(startOfMonth(viewMonth), { weekStartsOn: 0 }),
    end: endOfWeek(endOfMonth(viewMonth), { weekStartsOn: 0 }),
  });

  const isDisabledDay = (d: Date) => {
    const iso = format(d, ISO);
    return (!!min && iso < min) || (!!max && iso > max);
  };

  // Always include the year currently in view, so a stored value outside the
  // window (or a `min` far ahead) still renders as the selected option.
  const thisYear = new Date().getFullYear();
  const from = Math.min(
    min ? parseISO(min).getFullYear() : thisYear - YEARS_BACK,
    viewMonth.getFullYear()
  );
  const to = Math.max(
    max ? parseISO(max).getFullYear() : thisYear + YEARS_AHEAD,
    viewMonth.getFullYear()
  );
  const years = Array.from({ length: to - from + 1 }, (_, i) => to - i);

  const pick = (d: Date) => {
    if (isDisabledDay(d)) return;
    onChange(format(d, ISO));
    setOpen(false);
  };

  return (
    <div className="relative" ref={rootRef}>
      {/* aria-invalid is valid on <button> and lets the form focus the first
          invalid field; the jsx-a11y rule flags it as a false positive. */}
      {/* eslint-disable-next-line jsx-a11y/role-supports-aria-props */}
      <button
        type="button"
        id={id}
        disabled={disabled}
        aria-label={ariaLabel}
        aria-invalid={ariaInvalid}
        aria-haspopup="dialog"
        aria-expanded={open}
        onClick={toggle}
        className={cn(
          "flex h-10 w-full items-center gap-2 rounded-md border border-input bg-background px-3 py-2 text-left text-sm ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 aria-[invalid=true]:border-destructive aria-[invalid=true]:focus-visible:ring-destructive",
          // Room for the clear button, so a long date doesn't run under it.
          clearable && selected && "pr-10",
          className
        )}
      >
        <Calendar className="h-4 w-4 shrink-0 text-muted-foreground" />
        <span className={cn("truncate", !selected && "text-muted-foreground")}>
          {selected ? format(selected, "d MMMM yyyy", { locale: idLocale }) : placeholder}
        </span>
      </button>

      {/* Beside the trigger, not inside it: a button within a button is invalid
          markup, and the inner one would never receive the click. */}
      {clearable && selected && !disabled && (
        <button
          type="button"
          aria-label="Hapus tanggal"
          onClick={() => {
            onChange("");
            setOpen(false);
          }}
          className="absolute right-1.5 top-1/2 grid h-7 w-7 -translate-y-1/2 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        >
          <X className="h-3.5 w-3.5" />
        </button>
      )}

      {open &&
        coords &&
        createPortal(
          <div
            ref={panelRef}
            role="dialog"
            style={{
              position: "fixed",
              left: coords.left,
              ...(coords.flip
                ? { bottom: window.innerHeight - coords.top + 6 }
                : { top: coords.bottom + 6 }),
            }}
            className="z-[60] w-76 max-w-[calc(100vw-1rem)] rounded-xl border border-border bg-popover p-3 text-popover-foreground shadow-[var(--shadow-md)]"
          >
            <div className="mb-2 flex items-center justify-between">
              <button
                type="button"
                onClick={() => setViewMonth((m) => subMonths(m, 1))}
                className="grid h-7 w-7 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground"
                aria-label="Bulan sebelumnya"
              >
                <ChevronLeft className="h-4 w-4" />
              </button>
              <div className="flex items-center gap-1" style={{ fontFamily: "var(--font-display)" }}>
                <HeaderSelect
                  label="Bulan"
                  value={viewMonth.getMonth()}
                  onChange={(v) => setViewMonth((m) => setMonth(m, v))}
                  options={MONTHS.map((label, i) => ({ value: i, label }))}
                />
                <HeaderSelect
                  label="Tahun"
                  value={viewMonth.getFullYear()}
                  onChange={(v) => setViewMonth((m) => setYear(m, v))}
                  options={years.map((y) => ({ value: y, label: String(y) }))}
                />
              </div>
              <button
                type="button"
                onClick={() => setViewMonth((m) => addMonths(m, 1))}
                className="grid h-7 w-7 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground"
                aria-label="Bulan berikutnya"
              >
                <ChevronRight className="h-4 w-4" />
              </button>
            </div>

            <div className="mb-1 grid grid-cols-7 text-center text-[0.7rem] font-medium text-muted-foreground">
              {WEEKDAYS.map((w) => (
                <span key={w} className="py-1">
                  {w}
                </span>
              ))}
            </div>

            <div className="grid grid-cols-7 gap-0.5">
              {days.map((d) => {
                const outside = !isSameMonth(d, viewMonth);
                const isSelected = selected && isSameDay(d, selected);
                const disabledDay = isDisabledDay(d);
                return (
                  <button
                    key={d.toISOString()}
                    type="button"
                    disabled={disabledDay}
                    // The visible label is just the day number, which is ambiguous
                    // on its own (and repeats for the trailing days of the
                    // neighbouring months).
                    aria-label={format(d, "d MMMM yyyy", { locale: idLocale })}
                    aria-current={isToday(d) ? "date" : undefined}
                    onClick={() => pick(d)}
                    className={cn(
                      "grid h-8 w-8 place-items-center justify-self-center rounded-md text-sm transition-colors",
                      "hover:bg-accent hover:text-accent-foreground",
                      outside && "text-muted-foreground/50",
                      isToday(d) && !isSelected && "font-semibold text-[var(--brand-600)]",
                      isSelected &&
                        "bg-[var(--brand-600)] font-semibold text-white hover:bg-[var(--brand-700)] hover:text-white",
                      disabledDay && "cursor-not-allowed text-muted-foreground/30 line-through hover:bg-transparent"
                    )}
                  >
                    {format(d, "d")}
                  </button>
                );
              })}
            </div>
          </div>,
          document.body
        )}
    </div>
  );
}
