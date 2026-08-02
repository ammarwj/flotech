import { apiClient } from "./client";
import type {
  AdminPayment,
  AdminWallet,
  ApiEnvelope,
  PlatformSettingsPayload,
  EventPlanOrder,
  Withdrawal,
  WithdrawalStatus,
} from "@/types/api";

export interface CompleteWithdrawalInput {
  proof_url: string;
  transfer_reference?: string | null;
  admin_note?: string | null;
}

// ---- SaaS admin ----

export async function getAdminWithdrawals(status?: WithdrawalStatus): Promise<Withdrawal[]> {
  const { data } = await apiClient.get<ApiEnvelope<Withdrawal[]>>("/admin/withdrawals", {
    params: status ? { status } : undefined,
  });
  return data.data;
}

export async function processWithdrawal(id: string): Promise<Withdrawal> {
  const { data } = await apiClient.patch<ApiEnvelope<Withdrawal>>(
    `/admin/withdrawals/${id}/process`
  );
  return data.data;
}

export async function completeWithdrawal(
  id: string,
  payload: CompleteWithdrawalInput
): Promise<Withdrawal> {
  const { data } = await apiClient.patch<ApiEnvelope<Withdrawal>>(
    `/admin/withdrawals/${id}/complete`,
    payload
  );
  return data.data;
}

export async function rejectWithdrawal(id: string, adminNote: string): Promise<Withdrawal> {
  const { data } = await apiClient.patch<ApiEnvelope<Withdrawal>>(
    `/admin/withdrawals/${id}/reject`,
    { admin_note: adminNote }
  );
  return data.data;
}

export async function getAdminPayments(status = "paid"): Promise<AdminPayment[]> {
  const { data } = await apiClient.get<ApiEnvelope<AdminPayment[]>>("/admin/payments", {
    params: { status },
  });
  return data.data;
}

/** Voids the order and reverses the organizer's credit. Does NOT refund the buyer. */
export async function refundPayment(
  kind: AdminPayment["kind"],
  id: string,
  reason: string
): Promise<void> {
  const path = kind === "ticket_order" ? `/admin/ticket-orders/${id}/refund` : `/admin/teams/${id}/refund`;
  await apiClient.post(path, { reason });
}

export async function getAdminWallets(negativeOnly = false): Promise<AdminWallet[]> {
  const { data } = await apiClient.get<ApiEnvelope<AdminWallet[]>>("/admin/wallets", {
    params: negativeOnly ? { negative: 1 } : undefined,
  });
  return data.data;
}

// ---- Platform policy (super admin) ----

export async function getPlatformSettings(): Promise<PlatformSettingsPayload> {
  const { data } = await apiClient.get<ApiEnvelope<PlatformSettingsPayload>>("/admin/settings");
  return data.data;
}

export async function updatePlatformSettings(
  values: Record<string, number | boolean>
): Promise<PlatformSettingsPayload> {
  const { data } = await apiClient.put<ApiEnvelope<PlatformSettingsPayload>>(
    "/admin/settings",
    values
  );
  return data.data;
}

// ---- Manual plan payments ----

/**
 * Plan payments transferred straight into the platform's own account while the
 * gateway is off. Unlike an event's queue, ruling on these is super_admin work:
 * approving hands out a paid credit on nothing but a receipt.
 */
export async function getPendingPlanOrders(): Promise<EventPlanOrder[]> {
  const { data } = await apiClient.get<ApiEnvelope<EventPlanOrder[]>>("/admin/plan-orders");
  return data.data;
}

export async function approvePlanOrder(id: string): Promise<EventPlanOrder> {
  const { data } = await apiClient.post<ApiEnvelope<EventPlanOrder>>(
    `/admin/plan-orders/${id}/approve`
  );
  return data.data;
}

/**
 * Paid plans nobody has spent yet.
 *
 * Not a queue to clear — the credit never expires, so nothing here is overdue.
 * It is the ledger of entitlements the platform has been paid for and not yet
 * delivered, and the same set `plan-orders:remind-idle` mails on a schedule.
 */
export async function getIdlePlanCredits(): Promise<EventPlanOrder[]> {
  const { data } = await apiClient.get<ApiEnvelope<EventPlanOrder[]>>("/admin/plan-orders/idle");
  return data.data;
}

/** Events of one organization, thin, for the reassign picker. */
export interface AdminOrgEvent {
  id: string;
  name: string;
  start_date: string | null;
  plan: { id: string; name: string } | null;
}

export async function getAdminOrganizationEvents(orgId: string): Promise<AdminOrgEvent[]> {
  const { data } = await apiClient.get<ApiEnvelope<AdminOrgEvent[]>>(
    `/admin/organizations/${orgId}/events`
  );
  return data.data;
}

/**
 * Apply a spare paid credit to an event that already exists.
 *
 * The escape hatch for a plan bought against the wrong event. Not an upgrade —
 * both credits were paid in full, so the one being replaced goes back into the
 * organizer's pool rather than retiring.
 */
export async function reassignEventPlan(eventId: string, planOrderId: string): Promise<void> {
  await apiClient.post(`/admin/events/${eventId}/reassign-plan`, { plan_order_id: planOrderId });
}

export async function rejectPlanOrder(id: string, reason: string): Promise<EventPlanOrder> {
  const { data } = await apiClient.post<ApiEnvelope<EventPlanOrder>>(
    `/admin/plan-orders/${id}/reject`,
    { reason }
  );
  return data.data;
}
