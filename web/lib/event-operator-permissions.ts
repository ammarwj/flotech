import type { EventOperatorPermissions } from "@/types/api";

/**
 * The four independent permission categories, fixed in code — same reason the
 * backend hardcodes `can_ticketing`/`can_scheduling`/`can_registration`/
 * `can_documents` as columns rather than a dynamic catalogue (see
 * CLAUDE.md's event-operator pattern): the middleware gates by name, so the
 * set can't grow without touching routes anyway.
 */
export const OPERATOR_CATEGORIES = [
  "ticketing",
  "scheduling",
  "registration",
  "documents",
] as const;

export type OperatorCategory = (typeof OPERATOR_CATEGORIES)[number];

export const OPERATOR_CATEGORY_LABELS: Record<OperatorCategory, string> = {
  ticketing: "Tiketing & Box Office",
  scheduling: "Jadwal & Pertandingan",
  registration: "Pendaftaran Peserta",
  documents: "Dokumen & Sertifikat",
};

/** `ticketing` → `can_ticketing`, the column/field name the API reads. */
export function permissionKey(category: OperatorCategory): keyof EventOperatorPermissions {
  return `can_${category}`;
}

/**
 * True when every category is granted. "Semua Akses" in the UI is this,
 * derived — never a stored fifth field, so it can never disagree with the
 * four booleans that actually gate anything.
 */
export function isAllAccess(perm: EventOperatorPermissions): boolean {
  return OPERATOR_CATEGORIES.every((c) => perm[permissionKey(c)]);
}

/** True when at least one category is granted. */
export function hasAnyAccess(perm: EventOperatorPermissions): boolean {
  return OPERATOR_CATEGORIES.some((c) => perm[permissionKey(c)]);
}

/**
 * Builds the `can_*` payload the invite/update endpoints expect from a
 * `{ category: checked }` map, e.g. from checkbox state keyed by category name.
 */
export function permissionsPayload(
  checked: Partial<Record<OperatorCategory, boolean>>
): EventOperatorPermissions {
  return {
    can_ticketing: !!checked.ticketing,
    can_scheduling: !!checked.scheduling,
    can_registration: !!checked.registration,
    can_documents: !!checked.documents,
  };
}

/** Which categories are granted on a permission set, for rendering badges. */
export function grantedCategories(perm: EventOperatorPermissions): OperatorCategory[] {
  return OPERATOR_CATEGORIES.filter((c) => perm[permissionKey(c)]);
}

/**
 * Filters an event sub-page's own cross-links to other sub-pages, from
 * `event.viewer_permissions` (see `SportEvent.viewer_permissions` and
 * `EventResource::viewerPermissions()`).
 *
 * `null` = unrestricted (an org member — owner/admin/org-level `operator`
 * member) — every link stays, identical to today's behaviour. Non-null = an
 * EVENT OPERATOR, narrowed to the one category the destination route is
 * actually gated on server-side (`event.perm.<category>` in api.php) — a
 * link this returns `false` for would 403 if clicked, so it must not be
 * offered.
 *
 * There is no shared nav/sidebar component for these sub-pages (each one's
 * `PageHeader` hardcodes its own cross-links — see CLAUDE.md note on why a
 * global event menu has no meaning), so this is applied at each existing
 * link site rather than in one central place. What IS shared is this
 * function: every call site reads the same four booleans the same way,
 * so a destination's category can never be guessed differently on two pages.
 */
export function canAccessCategory(
  perm: EventOperatorPermissions | null,
  category: OperatorCategory
): boolean {
  return perm === null || perm[permissionKey(category)];
}

/**
 * True for an org member (owner/admin/org-level `operator`) — the
 * unrestricted case. False for an event operator, no matter which
 * categories they hold.
 *
 * For links that lead to a route an event operator cannot reach under ANY
 * permission at all — management of other operators, the domain add-on,
 * the registration form builder, the manual-payment queue, and the rest of
 * the `org.admin`/plain-`tenant` surfaces CLAUDE.md lists as permanently
 * excluded from the four categories. `canAccessCategory()` cannot express
 * this: there is no category whose grant would make these routes reachable.
 */
export function isOrgMember(perm: EventOperatorPermissions | null): boolean {
  return perm === null;
}
