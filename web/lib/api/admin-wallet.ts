import { apiClient } from "./client";
import type {
  AdminPayment,
  AdminWallet,
  ApiEnvelope,
  PlatformSetting,
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

// ---- Payout policy (super admin) ----

export async function getPlatformSettings(): Promise<PlatformSetting[]> {
  const { data } = await apiClient.get<ApiEnvelope<PlatformSetting[]>>("/admin/settings");
  return data.data;
}

export async function updatePlatformSettings(
  values: Record<string, number>
): Promise<PlatformSetting[]> {
  const { data } = await apiClient.put<ApiEnvelope<PlatformSetting[]>>("/admin/settings", values);
  return data.data;
}
