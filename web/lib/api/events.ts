import { apiClient } from "./client";
import type {
  ApiEnvelope,
  BracketConfig,
  EventStatus,
  Paginated,
  PayRegistrationResult,
  PublicEvent,
  PublicEventListItem,
  RegisterTeamResult,
  SportEvent,
  Team,
  TeamStatus,
  TournamentFormat,
  UploadSignResult,
} from "@/types/api";

/** One category row in the event create/update payload. */
export interface EventCategoryInput {
  /** Present when editing an existing category; omitted for a new one. */
  id?: string;
  name: string;
  slug?: string;
  tournament_format: TournamentFormat;
  registration_fee?: number;
  max_teams?: number | null;
  bracket_config?: BracketConfig | null;
}

export type EventInput = Partial<
  Pick<
    SportEvent,
    | "name"
    | "sport_type"
    | "status"
    | "start_date"
    | "end_date"
    | "registration_open"
    | "registration_close"
    | "location_name"
    | "location_address"
    | "description"
    | "banner_url"
  >
> & {
  slug?: string;
  /** The competitions inside the event; the backend full-replaces this list. */
  categories?: EventCategoryInput[];
};

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

/**
 * Enter a team the organizer collected outside the app (WhatsApp, paper, cash).
 * The API approves it on arrival and settles it as paid — see
 * RegistrationController::store.
 */
export async function createRegistration(
  orgId: string,
  eventId: string,
  payload: RegisterTeamPayload
): Promise<Team> {
  const { data } = await apiClient.post<ApiEnvelope<Team>>(
    `/organizations/${orgId}/events/${eventId}/registrations`,
    payload
  );
  return data.data;
}

/** Edit a team's details and roster from the organizer side (typos, late players). */
export async function updateRegistration(
  orgId: string,
  eventId: string,
  teamId: string,
  payload: RegisterTeamPayload
): Promise<Team> {
  const { data } = await apiClient.put<ApiEnvelope<Team>>(
    `/organizations/${orgId}/events/${eventId}/registrations/${teamId}`,
    payload
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

export interface PublicEventQuery {
  search?: string;
  /** Organizer slug — powers the organizer profile page. */
  org?: string;
  sport?: string;
  status?: EventStatus;
  page?: number;
  per_page?: number;
}

/** The public event catalog. Empty filters are dropped so they stay out of the URL. */
export async function getPublicEvents(
  query: PublicEventQuery = {}
): Promise<Paginated<PublicEventListItem>> {
  const params = Object.fromEntries(
    Object.entries(query).filter(([, v]) => v !== undefined && v !== "")
  );

  const { data } = await apiClient.get<ApiEnvelope<Paginated<PublicEventListItem>>>(
    "/public/events",
    { params }
  );
  return data.data;
}

export async function getPublicEvent(orgSlug: string, eventSlug: string): Promise<PublicEvent> {
  const { data } = await apiClient.get<ApiEnvelope<PublicEvent>>(
    `/public/events/${orgSlug}/${eventSlug}`
  );
  return data.data;
}

export interface RegisterTeamPayload {
  /** Which competition category inside the event the team is entering. */
  category_id: string;
  name: string;
  logo_url?: string | null;
  contact_name: string;
  contact_phone: string;
  /** Optional at registration — the roster can be completed later. `id` marks an existing player when editing. */
  players?: { id?: string; full_name: string; jersey_number?: string; position?: string }[];
  documents?: { id?: string; file_url: string; file_name?: string; document_type?: string }[];
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
  folder = "documents"
): Promise<UploadSignResult> {
  const { data } = await apiClient.post<ApiEnvelope<UploadSignResult>>("/uploads/sign", {
    file_name: fileName,
    content_type: contentType,
    folder,
  });
  return data.data;
}

/** Upload an image (already compressed) and get back a directly usable URL. */
export async function uploadImage(file: File, folder = "images"): Promise<string> {
  const form = new FormData();
  form.append("file", file);
  form.append("folder", folder);
  const { data } = await apiClient.post<ApiEnvelope<{ file_url: string; key: string }>>(
    "/uploads/image",
    form
  );
  return data.data.file_url;
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
  logo_url?: string | null;
  contact_name?: string;
  contact_phone?: string;
  /** Both lists are a full replacement: rows with an id are kept, omitted rows are deleted. */
  players?: { id?: string; full_name: string; jersey_number?: string; position?: string }[];
  documents?: { id?: string; file_url: string; file_name?: string | null; document_type?: string | null }[];
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
