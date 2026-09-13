import { apiClient } from "./client";
import type {
  ApiEnvelope,
  Faq,
  PlatformStats,
  SiteSettings,
  Testimonial,
} from "@/types/api";

// ---- Public ----

export async function getPublicTestimonials(): Promise<Testimonial[]> {
  const { data } = await apiClient.get<ApiEnvelope<Testimonial[]>>("/testimonials");
  return data.data;
}

export async function getPublicFaqs(): Promise<Faq[]> {
  const { data } = await apiClient.get<ApiEnvelope<Faq[]>>("/faqs");
  return data.data;
}

export async function getPublicSiteSettings(): Promise<SiteSettings> {
  const { data } = await apiClient.get<ApiEnvelope<SiteSettings>>("/site-settings");
  return data.data;
}

export async function getPublicStats(): Promise<PlatformStats> {
  const { data } = await apiClient.get<ApiEnvelope<PlatformStats>>("/stats");
  return data.data;
}

// ---- SaaS admin ----

export async function getAdminTestimonials(): Promise<Testimonial[]> {
  const { data } = await apiClient.get<ApiEnvelope<Testimonial[]>>("/admin/testimonials");
  return data.data;
}

export type TestimonialInput = Omit<Testimonial, "id">;

export async function createTestimonial(payload: TestimonialInput): Promise<Testimonial> {
  const { data } = await apiClient.post<ApiEnvelope<Testimonial>>("/admin/testimonials", payload);
  return data.data;
}

export async function updateTestimonial(
  id: string,
  payload: TestimonialInput
): Promise<Testimonial> {
  const { data } = await apiClient.put<ApiEnvelope<Testimonial>>(
    `/admin/testimonials/${id}`,
    payload
  );
  return data.data;
}

export async function deleteTestimonial(id: string): Promise<void> {
  await apiClient.delete(`/admin/testimonials/${id}`);
}

export async function getAdminFaqs(): Promise<Faq[]> {
  const { data } = await apiClient.get<ApiEnvelope<Faq[]>>("/admin/faqs");
  return data.data;
}

export type FaqInput = Omit<Faq, "id">;

export async function createFaq(payload: FaqInput): Promise<Faq> {
  const { data } = await apiClient.post<ApiEnvelope<Faq>>("/admin/faqs", payload);
  return data.data;
}

export async function updateFaq(id: string, payload: FaqInput): Promise<Faq> {
  const { data } = await apiClient.put<ApiEnvelope<Faq>>(`/admin/faqs/${id}`, payload);
  return data.data;
}

export async function deleteFaq(id: string): Promise<void> {
  await apiClient.delete(`/admin/faqs/${id}`);
}

export async function getAdminSiteSettings(): Promise<SiteSettings> {
  const { data } = await apiClient.get<ApiEnvelope<SiteSettings>>("/admin/site-settings");
  return data.data;
}

/**
 * Partial on purpose: /admin/site-settings saves one tab at a time, and the
 * API's `fill($request->validated())` only touches the keys it was sent — so an
 * omitted field keeps its stored value rather than being cleared.
 */
export type SiteSettingsInput = Partial<SiteSettings>;

/**
 * Upload the platform favicon. Unlike `uploadImage`, the file is sent as-is:
 * the server re-encodes it to a real .ico, which it cannot do from a WebP blob.
 */
export async function uploadFavicon(file: File): Promise<string> {
  const form = new FormData();
  form.append("file", file);
  const { data } = await apiClient.post<ApiEnvelope<{ file_url: string; key: string }>>(
    "/admin/uploads/favicon",
    form
  );
  return data.data.file_url;
}

export async function updateSiteSettings(payload: SiteSettingsInput): Promise<SiteSettings> {
  const { data } = await apiClient.put<ApiEnvelope<SiteSettings>>("/admin/site-settings", payload);
  return data.data;
}
