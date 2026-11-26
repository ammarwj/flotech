import { apiClient } from "./client";
import type {
  ApiEnvelope,
  EventOperator,
  EventOperatorInvite,
  EventOperatorInvitePreview,
  EventOperatorPermissions,
  MyOperatorEvent,
} from "@/types/api";

// ---- Organizer side (org.admin, under organizations/{orgId}) ----

export async function getEventOperators(orgId: string, eventId: string): Promise<EventOperator[]> {
  const { data } = await apiClient.get<ApiEnvelope<EventOperator[]>>(
    `/organizations/${orgId}/events/${eventId}/operators`
  );
  return data.data;
}

export async function getOperatorInvites(
  orgId: string,
  eventId: string
): Promise<EventOperatorInvite[]> {
  const { data } = await apiClient.get<ApiEnvelope<EventOperatorInvite[]>>(
    `/organizations/${orgId}/events/${eventId}/operator-invites`
  );
  return data.data;
}

export async function inviteEventOperator(
  orgId: string,
  eventId: string,
  payload: { email: string } & EventOperatorPermissions
): Promise<EventOperatorInvite> {
  const { data } = await apiClient.post<ApiEnvelope<EventOperatorInvite>>(
    `/organizations/${orgId}/events/${eventId}/operator-invites`,
    payload
  );
  return data.data;
}

export async function resendOperatorInvite(
  orgId: string,
  inviteId: string
): Promise<EventOperatorInvite> {
  const { data } = await apiClient.post<ApiEnvelope<EventOperatorInvite>>(
    `/organizations/${orgId}/operator-invites/${inviteId}/resend`
  );
  return data.data;
}

export async function revokeOperatorInvite(orgId: string, inviteId: string): Promise<void> {
  await apiClient.delete(`/organizations/${orgId}/operator-invites/${inviteId}`);
}

export async function updateOperatorPermissions(
  orgId: string,
  operatorId: string,
  payload: Partial<EventOperatorPermissions>
): Promise<EventOperator> {
  const { data } = await apiClient.patch<ApiEnvelope<EventOperator>>(
    `/organizations/${orgId}/operators/${operatorId}`,
    payload
  );
  return data.data;
}

export async function removeOperator(orgId: string, operatorId: string): Promise<void> {
  await apiClient.delete(`/organizations/${orgId}/operators/${operatorId}`);
}

// ---- Public/auth side (outside organizations/{orgId}) ----

/** Unauthenticated — the preview page has to render before anyone logs in. */
export async function getInvitePreview(token: string): Promise<EventOperatorInvitePreview> {
  const { data } = await apiClient.get<ApiEnvelope<EventOperatorInvitePreview>>(
    `/invites/${token}`
  );
  return data.data;
}

/** `auth:api` — accepting is the proof-of-email step. */
export async function acceptInvite(token: string): Promise<{ event_id: string }> {
  const { data } = await apiClient.post<ApiEnvelope<{ event_id: string }>>(
    `/invites/${token}/accept`
  );
  return data.data;
}

/** Pending invites addressed to the signed-in account's own email. */
export async function getMyOperatorInvites(): Promise<EventOperatorInvite[]> {
  const { data } = await apiClient.get<ApiEnvelope<EventOperatorInvite[]>>("/my-operator-invites");
  return data.data;
}

/** Events this account operates, for a user with no organization_members seat. */
export async function getMyOperatorEvents(): Promise<MyOperatorEvent[]> {
  const { data } = await apiClient.get<ApiEnvelope<MyOperatorEvent[]>>("/my-operator-events");
  return data.data;
}
