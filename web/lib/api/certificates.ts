import { apiClient } from "./client";
import { downloadBlob, fileNameFromDisposition } from "@/lib/download";
import type {
  ApiEnvelope,
  Certificate,
  CertificateBatch,
  CertificateField,
  CertificateFieldDef,
  CertificatePage,
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

/**
 * One page of issued certificates, newest first.
 *
 * Paginated because this list only grows — a fun run hands one to every runner.
 * `meta.total` is the count over the whole filter, so the bulk-zip button can
 * ask "is there anything to pack" without walking the pages.
 */
export async function getCertificates(
  orgId: string,
  params: { eventId?: string; page?: number } = {}
): Promise<CertificatePage> {
  const { data } = await apiClient.get<ApiEnvelope<CertificatePage>>(
    `/organizations/${orgId}/certificates`,
    {
      params: {
        ...(params.eventId ? { event_id: params.eventId } : {}),
        ...(params.page && params.page > 1 ? { page: params.page } : {}),
      },
    }
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

// ---- Bulk download ----

/**
 * Queue a zip of every issued certificate, optionally narrowed to one event,
 * and get back the id to poll.
 *
 * 202, never a finished file: pulling 500 PDFs out of the bucket runs for
 * minutes, and the API container serves every other request single-process
 * while it does. The server picks the rows from the organization's own
 * certificates — the client sends a filter, never a list of ids.
 */
export async function zipCertificates(
  orgId: string,
  eventId?: string
): Promise<{ batch_id: string; total: number }> {
  const { data } = await apiClient.post<ApiEnvelope<{ batch_id: string; total: number }>>(
    `/organizations/${orgId}/certificates/zip`,
    eventId ? { event_id: eventId } : {}
  );
  return data.data;
}

export async function getCertificateZipBatch(
  orgId: string,
  batchId: string
): Promise<CertificateBatch> {
  const { data } = await apiClient.get<ApiEnvelope<CertificateBatch>>(
    `/organizations/${orgId}/certificate-batches/${batchId}`
  );
  return data.data;
}

/** Save the finished zip. Blob through apiClient, for the reason above. */
export async function downloadCertificateZipBatch(orgId: string, batchId: string): Promise<void> {
  const response = await apiClient.get<Blob>(
    `/organizations/${orgId}/certificate-batches/${batchId}/download`,
    { responseType: "blob" }
  );

  downloadBlob(
    response.data,
    fileNameFromDisposition(response.headers["content-disposition"], "sertifikat.zip")
  );
}

// ---- Public ----

export async function verifyCertificate(number: string): Promise<CertificateVerification> {
  const { data } = await apiClient.get<ApiEnvelope<CertificateVerification>>(
    `/public/certificates/${encodeURIComponent(number)}`
  );
  return data.data;
}
