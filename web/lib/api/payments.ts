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
  /** `service_fee_unit * units` — the platform margin is charged per unit bought. */
  service_fee: number;
  service_fee_unit: number;
  units: number;
  total: number;
  midtrans_payments: string[];
}

/**
 * Which platform margin applies. The two are set independently in
 * /admin/settings: a participant paying an organizer (tickets, registration)
 * is not the same transaction as an organizer paying the platform (plans).
 */
export type FeeAudience = "participant" | "organizer";

/**
 * Fee breakdown per enabled Midtrans channel for a given amount — the channel
 * picker's data source.
 *
 * `units` is the basket size, because the platform's service fee is charged per
 * unit bought (three tickets carry three fees) while the gateway's own charge
 * stays per transaction. Defaults to 1: every flow except ticket purchase buys
 * exactly one thing, and the order path multiplies by the same number, so a
 * caller that forgets it quotes a total the server will not honour.
 */
export async function getPaymentChannels(
  amount: number,
  audience: FeeAudience,
  units = 1
): Promise<PaymentChannel[]> {
  const { data } = await apiClient.get<ApiEnvelope<PaymentChannel[]>>("/public/payment-channels", {
    params: { amount, audience, units },
  });
  return data.data;
}
