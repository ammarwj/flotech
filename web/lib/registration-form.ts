/**
 * The client's mirror of App\Support\RegistrationForm.
 *
 * Every branch this feature takes in the UI goes through a function here, for
 * the same reason lib/scoring.ts exists: four screens render the same schema
 * (public registration, the participant's own team page, the organizer's manual
 * entry dialog, and the builder itself), and a label or an accept-list spelled
 * out in a component is one that will disagree with the other three.
 */

export type CustomFieldType =
  | "short_text"
  | "long_text"
  | "select"
  | "date"
  /**
   * A tickbox whose stored value is WHEN it was ticked. The server stamps it
   * (RegistrationForm::normalizeAnswer), so the forms send only whether the box
   * is on — a timestamp from here would be one the person agreeing chose.
   */
  | "consent";

/**
 * When a field is asked at all: the trigger field's answer must be one of
 * `values`. Absent means always.
 *
 * The trigger is always a key defined EARLIER IN THE SAME LIST — the API
 * refuses anything else, which is what makes a cycle unrepresentable and
 * guarantees the trigger is on screen and answered before this field is
 * decided.
 */
export interface FieldCondition {
  field: string;
  values: string[];
}

/** File kinds a document slot may accept. Mirrors RegistrationForm::ACCEPTS. */
export type DocumentAccept = "pdf" | "jpg" | "png";

export interface CustomField {
  key: string;
  label: string;
  type: CustomFieldType;
  required: boolean;
  /**
   * Whether the answers show on the public event page — the squad list and the
   * roster dialog. Optional on the wire so a schema saved before this existed
   * still parses, and absent reads as private: publishing is opted into, never
   * defaulted (the backend normalizes the same way).
   */
  is_public?: boolean;
  /**
   * Whether the field gets its own row on the printed player album. Separate
   * from `is_public` on purpose: a sheet handed out at the venue and a page
   * anyone can index are two different decisions. Optional and absent-is-false
   * for the same reason — the backend normalizes identically.
   */
  in_album?: boolean;
  /** Only meaningful for `select`; [] everywhere else. */
  options: string[];
  /**
   * Guidance shown with the question. Null/absent means none at all — the forms
   * render nothing rather than an empty line, so the two spellings of "no
   * guidance" must not both be storable (the backend normalizes '' to null).
   */
  help_text?: string | null;
  /** A picture the question needs — a jersey size chart. Rendered with the field's label as `alt`. */
  help_image_url?: string | null;
  /** Null/absent = always asked. See isFieldVisible, the only reader. */
  visible_if?: FieldCondition | null;
}

/**
 * One published answer, already paired with its label by the API.
 *
 * The server sends only the fields it was told to publish, so there is nothing
 * to filter here — see RegistrationForm::publicAnswers(). A payload carrying
 * every answer and trusting the component to hide some is one render away from
 * leaking, which is why the shape is a list of what is public rather than the
 * raw `custom_fields` map the organizer's screens read.
 */
export interface PublicAnswer {
  key: string;
  label: string;
  value: string;
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
  team_official_fields: CustomField[];
  team_documents: DocumentSlot[];
  player_documents: DocumentSlot[];
  team_official_documents: DocumentSlot[];
}

export const EMPTY_SCHEMA: RegistrationFormSchema = {
  team_fields: [],
  player_fields: [],
  team_official_fields: [],
  team_documents: [],
  player_documents: [],
  team_official_documents: [],
};

export const FIELD_TYPES: { value: CustomFieldType; label: string }[] = [
  { value: "short_text", label: "Teks pendek" },
  { value: "long_text", label: "Teks panjang" },
  { value: "select", label: "Pilihan" },
  { value: "date", label: "Tanggal" },
  { value: "consent", label: "Persetujuan (centang)" },
];

/**
 * The value a consent box submits when ticked.
 *
 * Deliberately not a timestamp: the server stamps its own clock and ignores
 * whatever arrives, so sending one here would imply the client's is the one
 * recorded. "1" is the shortest thing normalizeAnswer() reads as ticked.
 */
export const CONSENT_ON = "1";

/** Whether a consent answer is ticked, for a form reading back its own state. */
export function isConsentGiven(value: string | undefined): boolean {
  return !!value && !["0", "false", "no"].includes(value.trim().toLowerCase());
}

/**
 * One stored answer as a human reads it — the client's mirror of
 * RegistrationForm::displayValue().
 *
 * Only `consent` needs translating, and it needs it badly: the stored value is
 * the instant it was ticked, and "2026-10-17T11:01:07+00:00" in an organizer's
 * participant list is a machine talking to itself. Everything else is already
 * what the entrant typed.
 *
 * Written here rather than in the one screen that shows it, because the public
 * page and the album already go through the server's copy and a third spelling
 * on the client is where the three start disagreeing. Returns "" for an
 * unanswered field so the caller decides whether a blank is a row or nothing —
 * the same split publicAnswers()/albumRows() make on the server.
 */
export function formatAnswer(
  field: CustomField,
  answers: CustomFieldAnswers | undefined
): string {
  const raw = (answers?.[field.key] ?? "").trim();
  if (raw === "" || field.type !== "consent") return raw;

  const at = new Date(raw);

  // A value that is not the instant this type promised: a schema whose type was
  // switched after answers came in. The raw text beats a wrong date — the same
  // call the server's copy makes.
  if (Number.isNaN(at.getTime())) return raw;

  return `Ya · ${at.toLocaleString("id-ID", {
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  })}`;
}

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
    team_official_fields: raw.team_official_fields ?? [],
    team_documents: raw.team_documents ?? [],
    player_documents: raw.player_documents ?? [],
    team_official_documents: raw.team_official_documents ?? [],
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
  return (
    schema.team_documents.length > 0 ||
    schema.player_documents.length > 0 ||
    schema.team_official_documents.length > 0
  );
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

/**
 * Whether any official row is half-finished — a name typed, something required
 * still missing. Mirrors hasIncompletePlayer exactly, for the bench instead of
 * the roster.
 */
export function hasIncompleteOfficial(
  schema: RegistrationFormSchema,
  officials: {
    full_name: string;
    custom_fields?: CustomFieldAnswers;
    documents?: DocumentRow[];
  }[]
): boolean {
  return officials.some(
    (o) =>
      o.full_name.trim() !== "" &&
      missingFor(
        schema.team_official_fields,
        schema.team_official_documents,
        o.custom_fields,
        o.documents ?? []
      ).length > 0
  );
}

/**
 * Whether this field is asked at all, given the answers so far.
 *
 * **The only reader of `visible_if` on the client**, mirroring
 * RegistrationForm::isVisible(). Two screens-worth of behaviour hang off it —
 * whether the field renders, and whether it is counted as missing — and those
 * two deciding separately is how a form ends up demanding a question it never
 * showed, with a disabled button and nothing on screen to explain it.
 */
export function isFieldVisible(
  field: CustomField,
  answers: CustomFieldAnswers | undefined
): boolean {
  const rule = field.visible_if;
  if (!rule) return true;

  return rule.values.includes((answers?.[rule.field] ?? "").trim());
}

/** The subset of a list that is asked, in order. */
export function visibleFields(
  fields: CustomField[],
  answers: CustomFieldAnswers | undefined
): CustomField[] {
  return fields.filter((f) => isFieldVisible(f, answers));
}

/**
 * What a missing field is called in the "Belum lengkap: …" line.
 *
 * A consent's label is a whole sentence — "Saya memahami risiko lomba dan
 * membebaskan penyelenggara dari tuntutan cedera" — because that sentence IS
 * what the person is agreeing to. Comma-joined with the others it turns the
 * line into a paragraph nobody can scan for the one thing they still have to
 * do, which is the line's only job. So the group is named once, by count,
 * rather than read out.
 *
 * Collapsed here rather than at each of the five call sites: they all render
 * the same list, and five copies of the rule would drift into a form that says
 * one thing and a roster card beside it that says another.
 */
function missingLabel(field: CustomField): string {
  return field.type === "consent" ? "" : field.label;
}

export function missingFor(
  fields: CustomField[],
  documents: DocumentSlot[],
  answers: CustomFieldAnswers | undefined,
  uploaded: DocumentRow[]
): string[] {
  const missing: string[] = [];
  let consents = 0;

  for (const field of fields) {
    // A question this entrant was never shown is not one they skipped — the
    // server skips it the same way, in answerErrors/assertFormComplete.
    if (!isFieldVisible(field, answers)) continue;

    if (field.required && !(answers?.[field.key] ?? "").trim()) {
      // Counted, not named — see missingLabel. Held aside so the group lands
      // after the fields rather than wherever the first unticked box sits.
      if (missingLabel(field) === "") {
        consents += 1;
        continue;
      }

      missing.push(field.label);
    }
  }

  if (consents > 0) {
    missing.push(consents === 1 ? "1 pernyataan persetujuan" : `${consents} pernyataan persetujuan`);
  }

  for (const doc of documents) {
    if (doc.required && !docFor(uploaded, doc.key)) {
      missing.push(doc.label);
    }
  }

  return missing;
}

/**
 * The key stored behind a label — it lands in `custom_fields` and
 * `document_type`. Derived, never typed: the builder used to show the key in a
 * box beside the label, and with nothing to tell the two apart organizers filled
 * both with prose and the save 422'd on a key that was never theirs to write.
 */
export function keyFrom(label: string): string {
  return label
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "_")
    .replace(/^_+|_+$/g, "")
    .slice(0, 50);
}

/**
 * `keyFrom`, kept distinct from every other row in the same list. Two fields
 * both called "Alamat" derive one key, and with the column hidden the organizer
 * can no longer pull them apart by hand — so the collision is settled here,
 * before reaching an API that would only reject it.
 */
export function uniqueKey(
  label: string,
  rows: Array<{ key: string }>,
  self: number
): string {
  const base = keyFrom(label);
  if (!base) return "";
  const taken = new Set(rows.filter((_, i) => i !== self).map((r) => r.key));
  if (!taken.has(base)) return base;
  for (let n = 2; ; n++) if (!taken.has(`${base}_${n}`)) return `${base}_${n}`;
}
