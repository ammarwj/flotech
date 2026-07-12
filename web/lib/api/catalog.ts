import { apiClient } from "./client";
import type { ApiEnvelope, Catalog, CatalogOption, SportDef } from "@/types/api";

/** The admin-managed vocabulary: sports, formats, tiebreakers, tiers. */
export async function getCatalog(): Promise<Catalog> {
  const { data } = await apiClient.get<ApiEnvelope<Catalog>>("/catalog");
  return data.data;
}

// ---- Super admin ----

/** A sport as the admin edits it: the public shape plus its editable fields. */
export interface AdminSport extends Omit<SportDef, "stats"> {
  id: string;
  is_active: boolean;
  sort_order: number;
  stats: AdminSportStat[];
}

export interface AdminSportStat {
  id?: string;
  stat_key: string;
  label: string;
  short: string;
  role: "goal" | "assist" | null;
  fair_play_weight: number;
  sort_order?: number;
}

export type SportInput = Omit<AdminSport, "id" | "stats">;

export async function getAdminSports(): Promise<AdminSport[]> {
  const { data } = await apiClient.get<ApiEnvelope<AdminSport[]>>("/admin/sports");
  return data.data;
}

export async function createSport(payload: Partial<SportInput>): Promise<AdminSport> {
  const { data } = await apiClient.post<ApiEnvelope<AdminSport>>("/admin/sports", payload);
  return data.data;
}

export async function updateSport(id: string, payload: Partial<SportInput>): Promise<AdminSport> {
  const { data } = await apiClient.put<ApiEnvelope<AdminSport>>(`/admin/sports/${id}`, payload);
  return data.data;
}

export async function deleteSport(id: string): Promise<void> {
  await apiClient.delete(`/admin/sports/${id}`);
}

/** Replace a sport's stat columns; order = display order, first = primary. */
export async function syncSportStats(id: string, stats: AdminSportStat[]): Promise<AdminSport> {
  const { data } = await apiClient.put<ApiEnvelope<AdminSport>>(`/admin/sports/${id}/stats`, {
    stats,
  });
  return data.data;
}

export interface AdminConfigOption extends CatalogOption {
  id: string;
  group: string;
  is_active: boolean;
  sort_order: number;
}

export type ConfigOptionInput = Omit<AdminConfigOption, "id">;

export async function getConfigOptions(group?: string): Promise<AdminConfigOption[]> {
  const { data } = await apiClient.get<ApiEnvelope<AdminConfigOption[]>>("/admin/config-options", {
    params: group ? { group } : undefined,
  });
  return data.data;
}

export async function createConfigOption(
  payload: Partial<ConfigOptionInput>
): Promise<AdminConfigOption> {
  const { data } = await apiClient.post<ApiEnvelope<AdminConfigOption>>(
    "/admin/config-options",
    payload
  );
  return data.data;
}

export async function updateConfigOption(
  id: string,
  payload: Partial<ConfigOptionInput>
): Promise<AdminConfigOption> {
  const { data } = await apiClient.put<ApiEnvelope<AdminConfigOption>>(
    `/admin/config-options/${id}`,
    payload
  );
  return data.data;
}

export async function deleteConfigOption(id: string): Promise<void> {
  await apiClient.delete(`/admin/config-options/${id}`);
}

/** What the backend can actually run — fills the "engine" pickers. */
export interface Engines {
  formats: string[];
  tiebreakers: string[];
  draw_methods: string[];
}

export async function getEngines(): Promise<Engines> {
  const { data } = await apiClient.get<ApiEnvelope<Engines>>("/admin/engines");
  return data.data;
}
