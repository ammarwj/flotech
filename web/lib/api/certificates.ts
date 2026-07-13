import { apiClient } from "./client";
import { downloadBlob, fileNameFromDisposition } from "@/lib/download";
import type {
  ApiEnvelope,
  Certificate,
  CertificateField,
  CertificateFieldDef,
  CertificateRecipients,
  CertificateTemplate,
  CertificateVerification,
} from "@/types/api";

// ---- Templates ----

/** The fields a template may place. Catalogued by config/certificate.php. */
export async function getCertificateFields(orgId: string): Promise<CertificateFieldDef[]> {
  const { data } = await apiClient.get<ApiEnvelope<CertificateFieldDef[]>>(
    `/organizations/${orgId}/certificate-fields`
  );
  return data.data;
}

export async function getCertificateTemplates(orgId: string): Promise<CertificateTemplate[]> {
  const { data } = await apiClient.get<ApiEnvelope<CertificateTemplate[]>>(
    `/organizations/${orgId}/certificate-templates`
  );
  return data.data;
}

export interface CertificateTemplateInput {
  name: string;
  background_url: string;
  orientation: "landscape" | "portrait";
  fields: CertificateField[];
}

export async function createCertificateTemplate(
  orgId: string,
  payload: CertificateTemplateInput
): Promise<CertificateTemplate> {
  const { data } = await apiClient.post<ApiEnvelope<CertificateTemplate>>(
    `/organizations/${orgId}/certificate-templates`,
    payload
  );
  return data.data;
}

export async function updateCertificateTemplate(
  orgId: string,
  templateId: string,
  payload: Partial<CertificateTemplateInput>
): Promise<CertificateTemplate> {
  const { data } = await apiClient.patch<ApiEnvelope<CertificateTemplate>>(
    `/organizations/${orgId}/certificate-templates/${templateId}`,
    payload
  );
  return data.data;
}

export async function deleteCertificateTemplate(orgId: string, templateId: string): Promise<void> {
  await apiClient.delete(`/organizations/${orgId}/certificate-templates/${templateId}`);
}

// ---- Issued certificates ----

export async function getCertificates(orgId: string, eventId?: string): Promise<Certificate[]> {
  const { data } = await apiClient.get<ApiEnvelope<Certificate[]>>(
    `/organizations/${orgId}/certificates`,
    { params: eventId ? { event_id: eventId } : {} }
  );
  return data.data;
}

export async function getCertificateRecipients(
  orgId: string,
  eventId: string
): Promise<CertificateRecipients> {
  const { data } = await apiClient.get<ApiEnvelope<CertificateRecipients>>(
    `/organizations/${orgId}/events/${eventId}/certificate-recipients`
  );
  return data.data;
}

export interface GenerateCertificatesPayload {
  certificate_template_id: string;
  award_title: string;
  recipients: { type: "team" | "player"; id: string }[];
  send_email?: boolean;
}

export async function generateCertificates(
  orgId: string,
  eventId: string,
  payload: GenerateCertificatesPayload
): Promise<Certificate[]> {
  const { data } = await apiClient.post<ApiEnvelope<Certificate[]>>(
    `/organizations/${orgId}/events/${eventId}/certificates`,
    payload
  );
  return data.data;
}

export async function sendCertificate(orgId: string, certificateId: string): Promise<void> {
  await apiClient.post(`/organizations/${orgId}/certificates/${certificateId}/send`);
}

export async function deleteCertificate(orgId: string, certificateId: string): Promise<void> {
  await apiClient.delete(`/organizations/${orgId}/certificates/${certificateId}`);
}

/**
 * The access token lives in memory, so a plain <a href> to the API would 401 —
 * the PDF has to come through apiClient as a blob. Same as the billing docs.
 */
export async function downloadCertificate(orgId: string, certificateId: string): Promise<void> {
  const response = await apiClient.get<Blob>(
    `/organizations/${orgId}/certificates/${certificateId}/download`,
    { responseType: "blob" }
  );

  downloadBlob(
    response.data,
    fileNameFromDisposition(response.headers["content-disposition"], "sertifikat.pdf")
  );
}

// ---- Public ----

export async function verifyCertificate(number: string): Promise<CertificateVerification> {
  const { data } = await apiClient.get<ApiEnvelope<CertificateVerification>>(
    `/public/certificates/${encodeURIComponent(number)}`
  );
  return data.data;
}
