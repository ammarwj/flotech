"use client";

import { createContext, useContext } from "react";

/**
 * The venue's timezone for the event currently on screen.
 *
 * Kickoff times travel as UTC instants, so every component that renders or
 * edits one needs this zone to be correct. That is a deep, wide set (schedule
 * page, public schedule, results, calendar, match cards, both editors), so it
 * rides in context rather than through four layers of props.
 *
 * Falls back to Asia/Jakarta — the column default — so a component mounted
 * outside a provider behaves like the app did before events carried a zone,
 * rather than silently drifting to the viewer's own.
 */
const EventTimezoneContext = createContext<string>("Asia/Jakarta");

export function EventTimezoneProvider({
  timezone,
  children,
}: {
  timezone: string | null | undefined;
  children: React.ReactNode;
}) {
  return (
    <EventTimezoneContext.Provider value={timezone || "Asia/Jakarta"}>
      {children}
    </EventTimezoneContext.Provider>
  );
}

export function useEventTimezone(): string {
  return useContext(EventTimezoneContext);
}
