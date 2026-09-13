import { apiClient } from "./client";
import type { ApiEnvelope } from "@/types/api";

export interface PaymentChannel {
  channel: string;
  label: string;
  /** Already includes `gateway_tax`; the two parts below are for display only. */
  gateway_fee: number;
  gateway_fee_base: number;
  gateway_tax: number;
  tax_percent: number;
  service_fee: number;
  total: number;
  midtrans_payments: string[];
}

/**
 * Which platform margin applies. The two are set independently in
 * /admin/settings: a participant paying an organizer (tickets, registration)
 * is not the same transaction as an organizer paying the platform (plans).
 */
export type FeeAudience = "participant" | "organizer";

/** Fee breakdown per enabled Midtrans channel for a given amount — the channel picker's data source. */
export async function getPaymentChannels(
  amount: number,
  audience: FeeAudience
): Promise<PaymentChannel[]> {
  const { data } = await apiClient.get<ApiEnvelope<PaymentChannel[]>>("/public/payment-channels", {
    params: { amount, audience },
  });
  return data.data;
}
