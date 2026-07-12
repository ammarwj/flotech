import { Badge, type BadgeProps } from "@/components/ui/badge";
import {
  EVENT_STATUS_LABELS,
  TEAM_STATUS_LABELS,
  WALLET_TX_STATUS_LABELS,
  WITHDRAWAL_STATUS_LABELS,
} from "@/lib/labels";
import type { EventStatus, TeamStatus, WalletTxStatus, WithdrawalStatus } from "@/types/api";

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

const WITHDRAWAL_VARIANT: Record<WithdrawalStatus, Variant> = {
  pending: "warning",
  processing: "info",
  completed: "success",
  rejected: "danger",
};

const WALLET_TX_VARIANT: Record<WalletTxStatus, Variant> = {
  pending: "warning",
  available: "success",
  cancelled: "neutral",
};

export function WithdrawalStatusBadge({ status }: { status: WithdrawalStatus }) {
  return (
    <Badge variant={WITHDRAWAL_VARIANT[status]} dot={status === "processing"}>
      {WITHDRAWAL_STATUS_LABELS[status]}
    </Badge>
  );
}

export function WalletTxStatusBadge({ status }: { status: WalletTxStatus }) {
  return <Badge variant={WALLET_TX_VARIANT[status]}>{WALLET_TX_STATUS_LABELS[status]}</Badge>;
}
