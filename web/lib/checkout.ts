import type { CheckoutResult } from "@/types/api";

/**
 * What actually happened when a plan checkout (or a retry) came back.
 *
 * - `manual` — the gateway is off; the organizer transfers by hand and uploads proof.
 * - `redirect` — send them to Midtrans.
 * - `paid` — already settled; the organizer is holding a credit to spend.
 * - `failed` — the bill is still outstanding and we could not open a payment.
 */
export type CheckoutOutcome = "manual" | "redirect" | "paid" | "failed";

/**
 * One reader for every checkout surface (create-event, /organizer/plans, /organizer/billing).
 *
 * `redirect_url === null` is not success. A Midtrans error returns null too —
 * MidtransService logs it and hands back an empty transaction — and the pages
 * used to read that as "paid", cheerfully announcing a purchase over a bill
 * still sitting at `past_due`. Nor is `mock` the answer: it means "no server key
 * configured", not "settled". The order's own status is the only thing that
 * knows, so ask it.
 *
 * These pages drifting apart is the bug this exists to prevent — put any
 * new branch here, not in a page.
 */
export function checkoutOutcome(res: CheckoutResult): CheckoutOutcome {
  if (res.payment_method === "manual") return "manual";
  if (res.redirect_url) return "redirect";
  if (res.plan_order.status === "paid") return "paid";
  return "failed";
}
