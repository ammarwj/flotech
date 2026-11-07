import { apiClient } from "./client";
import type { ApiEnvelope, RefundPayout } from "@/types/api";

/**
 * The transfer a refund leaves behind, on both sides of it.
 *
 * Granting a refund reverses the organizer's wallet credit immediately, but the
 * buyer's money is in the platform's own Midtrans account — so somebody has to
 * transfer it by hand. Two hops, two surfaces: the platform pays the organizer
 * (super admin), the organizer forwards it to their own participant.
 *
 * Neither call touches the wallet, and that is deliberate on the server: the
 * organizer's balance has already gone down, so crediting it back here would
 * cancel the refund in effect.
 */

export interface AdminRefundPayoutQueue {
  /** Oldest first — somebody is waiting on each of these. */
  pending: RefundPayout[];
  completed: RefundPayout[];
}

export interface CompleteRefundPayoutInput {
  /** Required: real money left the platform on somebody's word. */
  proof_url: string;
  transfer_reference?: string | null;
  admin_note?: string | null;
}

export interface ForwardRefundPayoutInput {
  forwarded_proof_url: string;
  forwarded_reference?: string | null;
}

// ---- platform side (super admin) ----

export async function getAdminRefundPayouts(): Promise<AdminRefundPayoutQueue> {
  const { data } = await apiClient.get<ApiEnvelope<AdminRefundPayoutQueue>>(
    "/admin/refund-payouts"
  );
  return data.data;
}

export async function completeRefundPayout(
  id: string,
  payload: CompleteRefundPayoutInput
): Promise<RefundPayout> {
  const { data } = await apiClient.patch<ApiEnvelope<RefundPayout>>(
    `/admin/refund-payouts/${id}/complete`,
    payload
  );
  return data.data;
}

// ---- organizer side ----

/**
 * Every refund debt on this organization, newest first.
 *
 * Rows still pending at the platform are included on purpose: that is the
 * organizer's answer to a participant asking where their money is.
 */
export async function getRefundPayouts(orgId: string): Promise<RefundPayout[]> {
  const { data } = await apiClient.get<ApiEnvelope<RefundPayout[]>>(
    `/organizations/${orgId}/refund-payouts`
  );
  return data.data;
}

export async function forwardRefundPayout(
  orgId: string,
  id: string,
  payload: ForwardRefundPayoutInput
): Promise<RefundPayout> {
  const { data } = await apiClient.patch<ApiEnvelope<RefundPayout>>(
    `/organizations/${orgId}/refund-payouts/${id}/forward`,
    payload
  );
  return data.data;
}
