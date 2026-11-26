"use client";

import { useParams } from "next/navigation";
import { useQuery } from "@tanstack/react-query";
import { getOrganizations } from "@/lib/api/organizations";
import { getMyOperatorEvents } from "@/lib/api/event-operators";

/**
 * Resolves the user's active organization (first owned/member org).
 * Phase 2 assumes one org per user; an org switcher can replace this later.
 *
 * A pure event operator (no `organization_members` row at all — see
 * `hasOperatorAccess()`) has an empty `GET /organizations`, so `org`/`orgId`
 * above would stay null forever and every event sub-page keyed on `orgId`
 * (`enabled: !!orgId`) would hang on its skeleton. Every one of those pages
 * lives under `/organizer/events/{id}/...`, so once the org list comes back
 * empty, the `{id}` segment already in the URL is used to look the operator
 * up in `GET my-operator-events` and borrow that event's `organization.id` —
 * the same id `GET my-operator-events`'s own payload would have handed the
 * `/organizer/operating` picker to link to, just resolved here instead so
 * every existing call site keeps working unchanged. This fallback query is
 * `enabled` only when the org list is confirmed empty AND a route `{id}` is
 * present, so an org member (who always has at least one org) never fires it
 * and their `orgId` is computed exactly as before — zero behavior change for
 * anyone with an `organization_members` row.
 */
export function useActiveOrg() {
  const query = useQuery({ queryKey: ["organizations"], queryFn: getOrganizations });
  const org = query.data?.[0] ?? null;
  const hasNoOrg = query.isSuccess && (query.data?.length ?? 0) === 0;

  const routeParams = useParams<{ id?: string }>();
  const eventId = typeof routeParams?.id === "string" ? routeParams.id : null;

  const operatorEventsQuery = useQuery({
    queryKey: ["my-operator-events"],
    queryFn: getMyOperatorEvents,
    enabled: hasNoOrg && !!eventId,
  });
  const operatorMatch = operatorEventsQuery.data?.find((e) => e.event.id === eventId) ?? null;

  const operatorFallbackPending = hasNoOrg && !!eventId && operatorEventsQuery.isLoading;

  return {
    org,
    orgId: org?.id ?? operatorMatch?.organization.id ?? null,
    /** Whoever runs the org: owner or admin member. Operators record only. */
    isOrgAdmin: org?.my_role === "owner" || org?.my_role === "admin",
    isLoading: query.isLoading || operatorFallbackPending,
    isError: query.isError,
    hasNoOrg,
  };
}
