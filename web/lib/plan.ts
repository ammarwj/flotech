import type { Organization, SportEvent } from "@/types/api";

/**
 * Active-event cap for an org's plan, mirroring the backend `max_active_events`
 * limit. Returns `null` when unlimited (`-1`) or undefined (no limit set).
 */
export function getActiveEventLimit(org?: Organization | null): number | null {
  const raw = org?.plan?.features?.max_active_events;
  if (raw === undefined || raw === null) return null;

  const limit = Number(raw);
  if (Number.isNaN(limit) || limit < 0) return null; // -1 = unlimited
  return limit;
}

/** An event counts against the limit unless it's finished or cancelled. */
export function isActiveEvent(event: Pick<SportEvent, "status">): boolean {
  return event.status !== "finished" && event.status !== "cancelled";
}

export function countActiveEvents(events?: SportEvent[] | null): number {
  return events?.filter(isActiveEvent).length ?? 0;
}

/** Whether creating another event would exceed the plan's active-event cap. */
export function isActiveEventLimitReached(
  org?: Organization | null,
  events?: SportEvent[] | null
): boolean {
  const limit = getActiveEventLimit(org);
  if (limit === null) return false;
  return countActiveEvents(events) >= limit;
}
