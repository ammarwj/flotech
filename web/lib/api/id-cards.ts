import { apiClient } from "./client";
import type {
  ApiEnvelope,
  IdCardField,
  IdCardFieldDef,
  IdCardTemplate,
} from "@/types/api";

// ---- Templates ----

/** The fields a card may place. Catalogued by config/id_card.php. */
export async function getIdCardFields(orgId: string): Promise<IdCardFieldDef[]> {
  const { data } = await apiClient.get<ApiEnvelope<IdCardFieldDef[]>>(
    `/organizations/${orgId}/id-card-fields`
  );
  return data.data;
}

export async function getIdCardTemplates(orgId: string): Promise<IdCardTemplate[]> {
  const { data } = await apiClient.get<ApiEnvelope<IdCardTemplate[]>>(
    `/organizations/${orgId}/id-card-templates`
  );
  return data.data;
}

export interface IdCardTemplateInput {
  name: string;
  background_url: string;
  /** Millimetres. The presets never reach the API — they just write two numbers. */
  width_mm: number;
  height_mm: number;
  fields: IdCardField[];
}

export async function createIdCardTemplate(
  orgId: string,
  payload: IdCardTemplateInput
): Promise<IdCardTemplate> {
  const { data } = await apiClient.post<ApiEnvelope<IdCardTemplate>>(
    `/organizations/${orgId}/id-card-templates`,
    payload
  );
  return data.data;
}

export async function updateIdCardTemplate(
  orgId: string,
  templateId: string,
  payload: Partial<IdCardTemplateInput>
): Promise<IdCardTemplate> {
  const { data } = await apiClient.patch<ApiEnvelope<IdCardTemplate>>(
    `/organizations/${orgId}/id-card-templates/${templateId}`,
    payload
  );
  return data.data;
}

export async function deleteIdCardTemplate(orgId: string, templateId: string): Promise<void> {
  await apiClient.delete(`/organizations/${orgId}/id-card-templates/${templateId}`);
}
