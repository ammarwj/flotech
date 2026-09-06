import { apiClient } from "./client";
import type { AdminEvent, ApiEnvelope, Paginated } from "@/types/api";

/**
 * Daftar event lintas-organisasi milik super admin, plus kendali custom domain.
 *
 * Terpisah dari `lib/api/events.ts` karena endpoint-nya memang lain: yang itu
 * per-organisasi (`/organizations/{id}/events`, di belakang middleware `tenant`),
 * yang ini `/admin/events` di belakang `superadmin`.
 */

export interface AdminEventQuery {
  q?: string;
  status?: string;
  organization_id?: string;
  /** Filter status domain: "none" | "pending" | "active" | "failed". */
  domain?: string;
  page?: number;
  per_page?: number;
}

export async function getAdminEvents(
  params: AdminEventQuery = {}
): Promise<Paginated<AdminEvent>> {
  const { data } = await apiClient.get<ApiEnvelope<Paginated<AdminEvent>>>("/admin/events", {
    params,
  });
  return data.data;
}

/**
 * Pasang domain (atau lepas, dengan `null`). **Tidak** menerbitkan SSL —
 * aktivasinya tombol terpisah karena ia membakar kuota Let's Encrypt dan baru
 * berhasil setelah DNS pemilik domain propagasi, yang bisa berjam-jam sesudahnya.
 */
export async function setAdminEventDomain(
  eventId: string,
  domain: string | null
): Promise<AdminEvent> {
  const { data } = await apiClient.put<ApiEnvelope<AdminEvent>>(
    `/admin/events/${eventId}/domain`,
    { custom_domain: domain }
  );
  return data.data;
}

/** Verifikasi DNS, terbitkan sertifikat, mulai melayani domainnya. */
export async function activateAdminEventDomain(eventId: string): Promise<AdminEvent> {
  const { data } = await apiClient.post<ApiEnvelope<AdminEvent>>(
    `/admin/events/${eventId}/domain/activate`
  );
  return data.data;
}

/** Berhenti melayani domain dan hapus sertifikatnya. */
export async function releaseAdminEventDomain(eventId: string): Promise<AdminEvent> {
  const { data } = await apiClient.delete<ApiEnvelope<AdminEvent>>(
    `/admin/events/${eventId}/domain`
  );
  return data.data;
}
