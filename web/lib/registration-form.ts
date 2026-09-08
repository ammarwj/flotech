/**
 * The client's mirror of App\Support\RegistrationForm.
 *
 * Every branch this feature takes in the UI goes through a function here, for
 * the same reason lib/scoring.ts exists: four screens render the same schema
 * (public registration, the participant's own team page, the organizer's manual
 * entry dialog, and the builder itself), and a label or an accept-list spelled
 * out in a component is one that will disagree with the other three.
 */

export type CustomFieldType = "short_text" | "long_text" | "select" | "date";

/** File kinds a document slot may accept. Mirrors RegistrationForm::ACCEPTS. */
export type DocumentAccept = "pdf" | "jpg" | "png";

export interface CustomField {
  key: string;
  label: string;
  type: CustomFieldType;
  required: boolean;
  /** Only meaningful for `select`; [] everywhere else. */
  options: string[];
}

export interface DocumentSlot {
  key: string;
  label: string;
  required: boolean;
  /** Never empty — the backend falls back to every kind rather than store a slot nobody can fill. */
  accept: DocumentAccept[];
}

export interface RegistrationFormSchema {
  team_fields: CustomField[];
  player_fields: CustomField[];
  team_documents: DocumentSlot[];
  player_documents: DocumentSlot[];
}

export const EMPTY_SCHEMA: RegistrationFormSchema = {
  team_fields: [],
  player_fields: [],
  team_documents: [],
  player_documents: [],
};

export const FIELD_TYPES: { value: CustomFieldType; label: string }[] = [
  { value: "short_text", label: "Teks pendek" },
  { value: "long_text", label: "Teks panjang" },
  { value: "select", label: "Pilihan" },
  { value: "date", label: "Tanggal" },
];

export const ACCEPTS: DocumentAccept[] = ["pdf", "jpg", "png"];

/** Answers to the custom fields, keyed the way they are stored. */
export type CustomFieldAnswers = Record<string, string>;

/** One uploaded file, in the shape both the API and the forms carry it. */
export interface DocumentRow {
  /** Present for a document already stored — that is what keeps it from being re-created. */
  id?: string;
  document_type?: string | null;
  file_name?: string | null;
  file_url: string;
}

/**
 * The schema an event defined, tolerating an event that isn't loaded yet and one
 * saved before this feature existed (both read as "nothing defined").
 *
 * Takes the event structurally rather than importing SportEvent/PublicEvent:
 * types/api.ts already imports the schema types from here, and both callers pass
 * an event that satisfies this shape.
 */
export function schemaOf(
  event?: { registration_form?: Partial<RegistrationFormSchema> | null } | null
): RegistrationFormSchema {
  const raw = event?.registration_form;
  if (!raw) return EMPTY_SCHEMA;

  return {
    team_fields: raw.team_fields ?? [],
    player_fields: raw.player_fields ?? [],
    team_documents: raw.team_documents ?? [],
    player_documents: raw.player_documents ?? [],
  };
}

/**
 * Whether this event asks for any documents at all.
 *
 * This is what makes "no document types defined ⇒ no upload UI whatsoever"
 * true: the sections render from their lists, so an empty one produces no
 * elements rather than an empty dropzone. The server enforces the same thing
 * from the other side (see documentTypeError) — one end alone is not a rule.
 */
export function hasDocuments(schema: RegistrationFormSchema): boolean {
  return schema.team_documents.length > 0 || schema.player_documents.length > 0;
}

/** The `accept` attribute for a file input on this slot. */
export function acceptAttr(doc: DocumentSlot): string {
  return doc.accept
    .flatMap((a) => (a === "jpg" ? [".jpg", ".jpeg"] : [`.${a}`]))
    .join(",");
}

/** Human-readable list of what a slot takes, e.g. "PDF, JPG, PNG". */
export function acceptLabel(doc: DocumentSlot): string {
  return doc.accept.map((a) => a.toUpperCase()).join(", ");
}

/** The uploaded file sitting in a slot, or undefined when it is still empty. */
export function docFor(rows: DocumentRow[], key: string): DocumentRow | undefined {
  return rows.find((d) => d.document_type === key);
}

/**
 * Put a file in a slot, replacing whatever was there.
 *
 * The replaced row's `id` is dropped along with it, so the backend prunes the
 * old file instead of keeping both — a slot holds one document by definition.
 */
export function putDoc(rows: DocumentRow[], key: string, row: DocumentRow | null): DocumentRow[] {
  const rest = rows.filter((d) => d.document_type !== key);
  return row ? [...rest, { ...row, document_type: key }] : rest;
}

/**
 * What a row is still missing, as labels — empty when it is complete.
 *
 * Mirrors TeamRosterService::rowErrors(), and is used the way the backend uses
 * it: a player row is checked only once a name has been typed. Returning labels
 * rather than a boolean is what lets the form say *what* is missing, which is
 * the whole difference between a disabled button and a usable one.
 */
/**
 * Whether any player row is half-finished — a name typed, something required
 * still missing.
 *
 * The server rejects such a request outright and stores nothing, so the three
 * forms use this to keep Save disabled instead of letting someone submit and
 * lose the whole roster to a 422. Rows with no name are not checked: an empty
 * row is one not filled in yet, and an empty roster stays perfectly legal.
 */
export function hasIncompletePlayer(
  schema: RegistrationFormSchema,
  players: {
    full_name: string;
    custom_fields?: CustomFieldAnswers;
    documents?: DocumentRow[];
  }[]
): boolean {
  return players.some(
    (p) =>
      p.full_name.trim() !== "" &&
      missingFor(schema.player_fields, schema.player_documents, p.custom_fields, p.documents ?? [])
        .length > 0
  );
}

export function missingFor(
  fields: CustomField[],
  documents: DocumentSlot[],
  answers: CustomFieldAnswers | undefined,
  uploaded: DocumentRow[]
): string[] {
  const missing: string[] = [];

  for (const field of fields) {
    if (field.required && !(answers?.[field.key] ?? "").trim()) {
      missing.push(field.label);
    }
  }

  for (const doc of documents) {
    if (doc.required && !docFor(uploaded, doc.key)) {
      missing.push(doc.label);
    }
  }

  return missing;
}
