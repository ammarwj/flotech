import { apiClient } from "./client";
import type { ApiEnvelope, DiscountCode } from "@/types/api";

/**
 * Super admin CRUD for plan checkout discount codes.
 *
 * There is no public counterpart: a code is typed by the organizer and checked
 * server-side, never listed. The organizer-facing half is `previewDiscount()`
 * in `lib/api/organizations.ts`.
 */
export async function getAdminDiscountCodes(): Promise<DiscountCode[]> {
  const { data } = await apiClient.get<ApiEnvelope<DiscountCode[]>>("/admin/discount-codes");
  return data.data;
}

/**
 * The counts are derived server-side, and `plan_ids` is a pivot rather than a
 * column — so neither is part of what the form submits.
 */
export type DiscountCodeInput = Omit<
  DiscountCode,
  "id" | "redeemed_count" | "reserved_count" | "created_at"
>;

export async function createDiscountCode(payload: DiscountCodeInput): Promise<DiscountCode> {
  const { data } = await apiClient.post<ApiEnvelope<DiscountCode>>(
    "/admin/discount-codes",
    payload
  );
  return data.data;
}

export async function updateDiscountCode(
  id: string,
  payload: DiscountCodeInput
): Promise<DiscountCode> {
  const { data } = await apiClient.put<ApiEnvelope<DiscountCode>>(
    `/admin/discount-codes/${id}`,
    payload
  );
  return data.data;
}

export async function deleteDiscountCode(id: string): Promise<void> {
  await apiClient.delete(`/admin/discount-codes/${id}`);
}
