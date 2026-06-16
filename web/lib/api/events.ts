import { apiClient } from "./client";
import type {
  ApiEnvelope,
  PayRegistrationResult,
  PublicEvent,
  RegisterTeamResult,
  SportEvent,
  Team,
  TeamStatus,
  UploadSignResult,
} from "@/types/api";

export type EventInput = Partial<
  Pick<
    SportEvent,
    | "name"
    | "sport_type"
    | "tournament_format"
    | "status"
    | "start_date"
    | "end_date"
    | "registration_open"
    | "registration_close"
    | "location_name"
    | "location_address"
    | "description"
    | "banner_url"
    | "max_teams"
    | "registration_fee"
  >
>;

// ---- Organizer (tenant-scoped) ----

export async function getEvents(orgId: string): Promise<SportEvent[]> {
  const { data } = await apiClient.get<ApiEnvelope<SportEvent[]>>(`/organizations/${orgId}/events`);
  return data.data;
}

export async function getEvent(orgId: string, eventId: string): Promise<SportEvent> {
  const { data } = await apiClient.get<ApiEnvelope<SportEvent>>(
    `/organizations/${orgId}/events/${eventId}`
  );
  return data.data;
}

export async function createEvent(orgId: string, payload: EventInput): Promise<SportEvent> {
  const { data } = await apiClient.post<ApiEnvelope<SportEvent>>(
    `/organizations/${orgId}/events`,
    payload
  );
  return data.data;
}

export async function updateEvent(
  orgId: string,
  eventId: string,
  payload: EventInput
): Promise<SportEvent> {
  const { data } = await apiClient.put<ApiEnvelope<SportEvent>>(
    `/organizations/${orgId}/events/${eventId}`,
    payload
  );
  return data.data;
}

export async function deleteEvent(orgId: string, eventId: string): Promise<void> {
  await apiClient.delete(`/organizations/${orgId}/events/${eventId}`);
}

export async function publishEvent(orgId: string, eventId: string): Promise<SportEvent> {
  const { data } = await apiClient.post<ApiEnvelope<SportEvent>>(
    `/organizations/${orgId}/events/${eventId}/publish`
  );
  return data.data;
}

export async function getRegistrations(orgId: string, eventId: string): Promise<Team[]> {
  const { data } = await apiClient.get<ApiEnvelope<Team[]>>(
    `/organizations/${orgId}/events/${eventId}/registrations`
  );
  return data.data;
}

export async function updateRegistrationStatus(
  orgId: string,
  eventId: string,
  teamId: string,
  status: TeamStatus,
  groupName?: string
): Promise<Team> {
  const { data } = await apiClient.patch<ApiEnvelope<Team>>(
    `/organizations/${orgId}/events/${eventId}/registrations/${teamId}`,
    { status, group_name: groupName }
  );
  return data.data;
}

// ---- Public ----

export async function getPublicEvent(orgSlug: string, eventSlug: string): Promise<PublicEvent> {
  const { data } = await apiClient.get<ApiEnvelope<PublicEvent>>(
    `/public/events/${orgSlug}/${eventSlug}`
  );
  return data.data;
}

export interface RegisterTeamPayload {
  name: string;
  city?: string;
  jersey_color?: string;
  contact_name: string;
  contact_phone: string;
  players: { full_name: string; jersey_number?: string; position?: string }[];
  documents?: { file_url: string; file_name?: string; document_type?: string }[];
}

export async function registerTeam(
  orgSlug: string,
  eventSlug: string,
  payload: RegisterTeamPayload
): Promise<RegisterTeamResult> {
  const { data } = await apiClient.post<ApiEnvelope<RegisterTeamResult>>(
    `/public/events/${orgSlug}/${eventSlug}/register`,
    payload
  );
  return data.data;
}

// ---- Uploads + participant ----

export async function signUpload(
  fileName: string,
  contentType?: string,
  folder = "registrations"
): Promise<UploadSignResult> {
  const { data } = await apiClient.post<ApiEnvelope<UploadSignResult>>("/uploads/sign", {
    file_name: fileName,
    content_type: contentType,
    folder,
  });
  return data.data;
}

export async function getMyTeams(): Promise<Team[]> {
  const { data } = await apiClient.get<ApiEnvelope<Team[]>>("/my-teams");
  return data.data;
}

export async function getMyTeam(teamId: string): Promise<Team> {
  const { data } = await apiClient.get<ApiEnvelope<Team>>(`/my-teams/${teamId}`);
  return data.data;
}

export interface UpdateMyTeamPayload {
  name?: string;
  city?: string | null;
  jersey_color?: string | null;
  logo_url?: string | null;
  contact_name?: string;
  contact_phone?: string;
  players?: { id?: string; full_name: string; jersey_number?: string; position?: string }[];
}

export async function updateMyTeam(teamId: string, payload: UpdateMyTeamPayload): Promise<Team> {
  const { data } = await apiClient.patch<ApiEnvelope<Team>>(`/my-teams/${teamId}`, payload);
  return data.data;
}

export async function withdrawMyTeam(teamId: string): Promise<Team> {
  const { data } = await apiClient.post<ApiEnvelope<Team>>(`/my-teams/${teamId}/withdraw`);
  return data.data;
}

export async function payRegistration(teamId: string): Promise<PayRegistrationResult> {
  const { data } = await apiClient.post<ApiEnvelope<PayRegistrationResult>>(
    `/my-teams/${teamId}/pay`
  );
  return data.data;
}
