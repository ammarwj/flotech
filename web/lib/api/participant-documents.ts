import { apiClient } from "./client";
import { fileNameFromDisposition } from "@/lib/download";

export type DocumentKind = "invoice" | "receipt";

export interface ParticipantDocument {
  blob: Blob;
  fileName: string;
}

/**
 * A participant's own invoice or receipt, for a ticket purchase or a
 * registration fee.
 *
 * Both live behind the same shape, so one function serves them: tickets are
 * addressed by the unguessable order id (the buyer never signs up and there is
 * nothing to authenticate), teams by the manager's session.
 *
 * The request has to go through apiClient rather than a plain <a href>: the
 * access token lives in memory, so a bare link would 401 — and the ticket
 * endpoint would answer, but the team one would not.
 */
export type DocumentSubject =
  | { kind: "ticket-order"; id: string }
  /** The manager's own copy, scoped by their session. */
  | { kind: "my-team"; id: string }
  /** The same team's documents from the organizer's side, scoped by the event. */
  | { kind: "registration"; orgId: string; eventId: string; id: string };

function pathFor(subject: DocumentSubject, document: DocumentKind): string {
  if (subject.kind === "registration") {
    return `/organizations/${subject.orgId}/events/${subject.eventId}/registrations/${subject.id}/${document}`;
  }

  const base = subject.kind === "ticket-order" ? "ticket-orders" : "my-teams";

  return `/${base}/${subject.id}/${document}`;
}

export async function getParticipantDocument(
  subject: DocumentSubject,
  document: DocumentKind
): Promise<ParticipantDocument> {
  const response = await apiClient.get<Blob>(pathFor(subject, document), {
    responseType: "blob",
  });

  return {
    blob: response.data,
    fileName: fileNameFromDisposition(
      response.headers["content-disposition"],
      `${document === "receipt" ? "Kwitansi" : "Invoice"}-${subject.id}.pdf`
    ),
  };
}
