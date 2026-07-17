import { apiClient } from "./client";
import type { ApiEnvelope, Faq, Testimonial } from "@/types/api";

// ---- Public ----

export async function getPublicTestimonials(): Promise<Testimonial[]> {
  const { data } = await apiClient.get<ApiEnvelope<Testimonial[]>>("/testimonials");
  return data.data;
}

export async function getPublicFaqs(): Promise<Faq[]> {
  const { data } = await apiClient.get<ApiEnvelope<Faq[]>>("/faqs");
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
