import { apiClient } from "./client";
import type { ApiEnvelope, DiscountPreview } from "@/types/api";

/**
 * What a discount code would do to a participant registration's price.
 *
 * One function for both rails — a competition category and a race category
 * differ only in which id is sent — because two copies would be two places to
 * keep in step, and the drift would show as a code the preview accepts and the
 * till refuses.
 *
 * Same resolver the registration itself runs, so that cannot happen on the
 * server side either. Refusals come back 422 with a `discount_code` field
 * error: render them inline under the input, not as a toast.
 *
 * **The returned `amount` is what the channel picker must be given.** The
 * client never computes a discount itself — a fee previewed over a price nobody
 * is charged is the `effective_payment_method` bug in another shape.
 */
export async function previewParticipantDiscount(
  orgSlug: string,
  eventSlug: string,
  code: string,
  category: { categoryId: string } | { raceCategoryId: string }
): Promise<DiscountPreview> {
  const { data } = await apiClient.post<ApiEnvelope<DiscountPreview>>(
    `/public/events/${orgSlug}/${eventSlug}/discount-preview`,
    {
      discount_code: code,
      ...("categoryId" in category
        ? { category_id: category.categoryId }
        : { race_category_id: category.raceCategoryId }),
    }
  );
  return data.data;
}
