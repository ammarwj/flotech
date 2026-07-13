import { apiClient } from "./client";
import type {
  ApiEnvelope,
  PurchaseResult,
  ScanResponse,
  TicketCategory,
  TicketOrder,
  TicketReport,
} from "@/types/api";

export interface TicketCategoryInput {
  name: string;
  description?: string | null;
  price: number;
  quota?: number | null;
  sale_start?: string | null;
  sale_end?: string | null;
  benefits?: string[];
  is_transferable?: boolean;
  is_active?: boolean;
}

// ---- Organizer (tenant-scoped) ----

export async function getTicketCategories(
  orgId: string,
  eventId: string
): Promise<TicketCategory[]> {
  const { data } = await apiClient.get<ApiEnvelope<TicketCategory[]>>(
    `/organizations/${orgId}/events/${eventId}/ticket-categories`
  );
  return data.data;
}

export async function createTicketCategory(
  orgId: string,
  eventId: string,
  payload: TicketCategoryInput
): Promise<TicketCategory> {
  const { data } = await apiClient.post<ApiEnvelope<TicketCategory>>(
    `/organizations/${orgId}/events/${eventId}/ticket-categories`,
    payload
  );
  return data.data;
}

export async function updateTicketCategory(
  orgId: string,
  categoryId: string,
  payload: Partial<TicketCategoryInput>
): Promise<TicketCategory> {
  const { data } = await apiClient.patch<ApiEnvelope<TicketCategory>>(
    `/organizations/${orgId}/ticket-categories/${categoryId}`,
    payload
  );
  return data.data;
}

export async function deleteTicketCategory(orgId: string, categoryId: string): Promise<void> {
  await apiClient.delete(`/organizations/${orgId}/ticket-categories/${categoryId}`);
}

/** Buyer list for an event — owner/admin only (the rows carry buyer contacts). */
export async function getTicketOrders(orgId: string, eventId: string): Promise<TicketOrder[]> {
  const { data } = await apiClient.get<ApiEnvelope<TicketOrder[]>>(
    `/organizations/${orgId}/events/${eventId}/ticket-orders`
  );
  return data.data;
}

export async function getTicketReport(orgId: string, eventId: string): Promise<TicketReport> {
  const { data } = await apiClient.get<ApiEnvelope<TicketReport>>(
    `/organizations/${orgId}/events/${eventId}/ticket-report`
  );
  return data.data;
}

export async function scanTicket(
  orgId: string,
  eventId: string,
  qrCode: string
): Promise<ScanResponse> {
  const { data } = await apiClient.post<ApiEnvelope<ScanResponse>>(
    `/organizations/${orgId}/events/${eventId}/scan`,
    { qr_code: qrCode }
  );
  return data.data;
}

// ---- Public ----

export async function getPublicTicketCategories(
  orgSlug: string,
  eventSlug: string
): Promise<TicketCategory[]> {
  const { data } = await apiClient.get<ApiEnvelope<TicketCategory[]>>(
    `/public/events/${orgSlug}/${eventSlug}/tickets`
  );
  return data.data;
}

export interface PurchasePayload {
  ticket_category_id: string;
  quantity: number;
  buyer_name: string;
  buyer_email: string;
  buyer_phone?: string;
  holder_names?: string[];
}

export async function purchaseTickets(
  orgSlug: string,
  eventSlug: string,
  payload: PurchasePayload
): Promise<PurchaseResult> {
  const { data } = await apiClient.post<ApiEnvelope<PurchaseResult>>(
    `/public/events/${orgSlug}/${eventSlug}/tickets/purchase`,
    payload
  );
  return data.data;
}

export async function getTicketOrder(orderId: string): Promise<TicketOrder> {
  const { data } = await apiClient.get<ApiEnvelope<TicketOrder>>(`/ticket-orders/${orderId}`);
  return data.data;
}
