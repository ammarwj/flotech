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
  /**
   * A fun run entry, addressed by its unguessable id exactly as a ticket order
   * is — the runner may never have signed up, and in this phase the receipt is
   * what they show at the start line, so a session requirement would lock them
   * out of their only proof.
   */
  | { kind: "race-entry"; id: string }
  /** The manager's own copy, scoped by their session. */
  | { kind: "my-team"; id: string }
  /** The same team's documents from the organizer's side, scoped by the event. */
  | { kind: "registration"; orgId: string; eventId: string; id: string };

/** The id-addressed subjects and the path segment each uses. */
const BY_ID: Record<"ticket-order" | "race-entry" | "my-team", string> = {
  "ticket-order": "ticket-orders",
  "race-entry": "race-entries",
  "my-team": "my-teams",
};

function pathFor(subject: DocumentSubject, document: DocumentKind): string {
  if (subject.kind === "registration") {
    return `/organizations/${subject.orgId}/events/${subject.eventId}/registrations/${subject.id}/${document}`;
  }

  // A map rather than a chain of ternaries: with three id-addressed subjects a
  // chain stops reading as a choice and starts reading as a default, and the
  // one that falls off the end is silently the wrong endpoint.
  return `/${BY_ID[subject.kind]}/${subject.id}/${document}`;
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
