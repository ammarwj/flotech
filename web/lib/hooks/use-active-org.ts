"use client";

import { useQuery } from "@tanstack/react-query";
import { getOrganizations } from "@/lib/api/organizations";

/**
 * Resolves the user's active organization (first owned/member org).
 * Phase 2 assumes one org per user; an org switcher can replace this later.
 */
export function useActiveOrg() {
  const query = useQuery({ queryKey: ["organizations"], queryFn: getOrganizations });
  const org = query.data?.[0] ?? null;

  return {
    org,
    orgId: org?.id ?? null,
    isLoading: query.isLoading,
    isError: query.isError,
    hasNoOrg: query.isSuccess && (query.data?.length ?? 0) === 0,
  };
}
