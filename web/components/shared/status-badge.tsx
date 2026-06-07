import { Badge, type BadgeProps } from "@/components/ui/badge";
import { EVENT_STATUS_LABELS, TEAM_STATUS_LABELS } from "@/lib/labels";
import type { EventStatus, TeamStatus } from "@/types/api";

type Variant = NonNullable<BadgeProps["variant"]>;

const TEAM_VARIANT: Record<TeamStatus, Variant> = {
  pending: "warning",
  approved: "success",
  rejected: "danger",
  disqualified: "danger",
  withdrawn: "neutral",
};

const EVENT_VARIANT: Record<EventStatus, Variant> = {
  draft: "neutral",
  open: "success",
  registration_closed: "warning",
  ongoing: "info",
  finished: "neutral",
  cancelled: "danger",
};

export function TeamStatusBadge({ status }: { status: TeamStatus }) {
  return <Badge variant={TEAM_VARIANT[status]}>{TEAM_STATUS_LABELS[status]}</Badge>;
}

export function EventStatusBadge({ status }: { status: EventStatus }) {
  const variant = EVENT_VARIANT[status];
  return (
    <Badge variant={variant} dot={status === "ongoing"}>
      {EVENT_STATUS_LABELS[status]}
    </Badge>
  );
}
