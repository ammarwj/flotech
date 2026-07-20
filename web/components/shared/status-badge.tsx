import { Badge, type BadgeProps } from "@/components/ui/badge";
import {
  EVENT_STATUS_LABELS,
  SUBSCRIPTION_STATUS_LABELS,
  TEAM_STATUS_LABELS,
  TICKET_ORDER_STATUS_LABELS,
  WALLET_TX_STATUS_LABELS,
  WITHDRAWAL_STATUS_LABELS,
} from "@/lib/labels";
import type {
  EventStatus,
  Match,
  SubscriptionStatus,
  TeamStatus,
  TicketOrderStatus,
  WalletTxStatus,
  WithdrawalStatus,
} from "@/types/api";

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

/**
 * A fixture's state in one chip.
 *
 * It takes the whole match, not a bare status, because the state an organizer
 * needs is not a function of `status` alone: a finished result still has to say
 * whether it counts yet, and a walkover is a finish nobody played. So
 * MATCH_STATUS_LABELS.finished ("Selesai") is never rendered here — a finished
 * match always resolves to one of three truer things.
 *
 * The confirmation wording is lifted verbatim from MatchConfirmBar, which now
 * keeps only its buttons: one place states, one place acts.
 */
export function MatchStatusBadge({ match }: { match: Match }) {
  if (match.status === "cancelled") return <Badge variant="danger">Dibatalkan</Badge>;

  if (match.status === "ongoing") {
    return (
      <Badge variant="info" dot>
        Berlangsung
      </Badge>
    );
  }

  if (match.status === "finished") {
    // A lone team with no opponent walked over; there is no scoreline to confirm.
    if (match.home_team_id && !match.away_team_id) {
      return <Badge variant="neutral">Menang WO</Badge>;
    }
    return match.confirmed ? (
      <Badge variant="success">Hasil final</Badge>
    ) : (
      <Badge variant="warning">Menunggu konfirmasi</Badge>
    );
  }

  return <Badge variant="neutral">Terjadwal</Badge>;
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

const TICKET_ORDER_VARIANT: Record<TicketOrderStatus, Variant> = {
  pending: "warning",
  paid: "success",
  cancelled: "neutral",
  refunded: "danger",
};

export function TicketOrderStatusBadge({ status }: { status: TicketOrderStatus }) {
  return (
    <Badge variant={TICKET_ORDER_VARIANT[status]}>{TICKET_ORDER_STATUS_LABELS[status]}</Badge>
  );
}

const SUBSCRIPTION_VARIANT: Record<SubscriptionStatus, Variant> = {
  active: "success",
  past_due: "warning",
  cancelled: "danger",
  expired: "neutral",
};

export function SubscriptionStatusBadge({ status }: { status: SubscriptionStatus }) {
  return (
    <Badge variant={SUBSCRIPTION_VARIANT[status]}>{SUBSCRIPTION_STATUS_LABELS[status]}</Badge>
  );
}
