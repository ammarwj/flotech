import { apiClient } from "./client";
import type {
  ApiEnvelope,
  EventViewBreakdown,
  EventViewStats,
  OrgViewBreakdown,
  OrgViewStats,
} from "@/types/api";

/**
 * Traffic counters are the one thing people watch change, so they opt out of
 * the app-wide 60s staleTime and the disabled focus refetch: opening a public
 * page in another tab and flipping back must show the new number, not a
 * minute-old one.
 */
export const LIVE_STATS_OPTIONS = {
  staleTime: 0,
  refetchOnWindowFocus: true,
  refetchOnMount: true,
} as const;

// ---- Beacon (public) ----

/**
 * Tell the API this event page was opened.
 *
 * Fire-and-forget: callers must swallow failures. A visitor closing the tab
 * mid-flight is normal and must never surface as an error, on screen or in
 * Sentry. The endpoint always answers 202, whether the hit was counted,
 * deduplicated, or dropped as a bot.
 */
export async function recordEventView(orgSlug: string, eventSlug: string): Promise<void> {
  await apiClient.post(`/public/events/${orgSlug}/${eventSlug}/view`);
}

// ---- Organizer ----

export async function getEventViewStats(
  orgId: string,
  eventId: string
): Promise<EventViewStats> {
  const { data } = await apiClient.get<ApiEnvelope<EventViewStats>>(
    `/organizations/${orgId}/events/${eventId}/view-stats`
  );
  return data.data;
}

export async function getOrgViewStats(orgId: string): Promise<OrgViewStats> {
  const { data } = await apiClient.get<ApiEnvelope<OrgViewStats>>(
    `/organizations/${orgId}/view-stats`
  );
  return data.data;
}

// ---- Super admin ----

export async function getAdminViewStats(): Promise<EventViewStats> {
  const { data } = await apiClient.get<ApiEnvelope<EventViewStats>>("/admin/view-stats");
  return data.data;
}

export async function getAdminViewsByOrganization(limit = 20): Promise<OrgViewBreakdown[]> {
  const { data } = await apiClient.get<ApiEnvelope<{ items: OrgViewBreakdown[] }>>(
    "/admin/view-stats/organizations",
    { params: { limit } }
  );
  return data.data.items;
}

export async function getAdminViewsByEvent(
  params: { limit?: number; organization_id?: string } = {}
): Promise<EventViewBreakdown[]> {
  const { data } = await apiClient.get<ApiEnvelope<{ items: EventViewBreakdown[] }>>(
    "/admin/view-stats/events",
    { params: { limit: 20, ...params } }
  );
  return data.data.items;
}
