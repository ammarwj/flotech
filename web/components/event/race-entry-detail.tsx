"use client";

import { Check, ChevronDown, ExternalLink, FileText, Minus } from "lucide-react";

import type { RaceEntry } from "@/types/api";
import {
  formatAnswer,
  isFieldVisible,
  type CustomField,
  type DocumentSlot,
  type RegistrationFormSchema,
} from "@/lib/registration-form";
import { rupiah } from "@/lib/labels";
import { cn } from "@/lib/utils";

/**
 * Everything one runner filled in, under their own row.
 *
 * The organizer's table shows six columns because a start list is read by
 * scanning it; the other twenty answers are read one entrant at a time — "what
 * jersey size did this person pick", "did they consent". Before this they were
 * reachable only by exporting the sheet, which is the wrong tool for one row.
 *
 * **Five blocks, five shapes, because the content is five kinds of thing.** An
 * earlier pass rendered all of it as one label-over-value grid, and it read as
 * a wall: a blood group carried the same visual weight as a student number, and
 * the consents — whose label is a full sentence and whose value is a short
 * timestamp — had the sentence in the smallest muted type above the date in the
 * largest. The shapes here are what encode the difference:
 *
 * - identity is a header, because a name is what you check you have the right
 *   person by;
 * - the emergency contact is set apart and its number is the largest thing in
 *   the panel, because it is read out loud under pressure at the venue;
 * - form answers are a two-column grid of label/value pairs;
 * - consents are a checklist, read down the ticks rather than across;
 * - money is a right-aligned ledger, so the digits line up to be compared.
 *
 * It reads the SCHEMA, not the stored map: the fields come out in the order the
 * form asked them, and a key left over from a question the organizer has since
 * removed does not grow a row with no label. `isFieldVisible` is asked for the
 * same reason the forms ask it — a conditional field nobody was shown has no
 * answer, and a labelled blank there would read as data this person withheld.
 */
export function RaceEntryDetail({
  entry,
  schema,
}: {
  entry: RaceEntry;
  schema: RegistrationFormSchema;
}) {
  const asked = schema.team_fields.filter((f) =>
    isFieldVisible(f, entry.custom_fields),
  );

  // Consents are pulled out by TYPE, not by key or label: an organizer names
  // them whatever they like, and matching on a prefix would quietly stop
  // working the first time somebody writes "Persetujuan" instead.
  const consents = asked.filter((f) => f.type === "consent");
  const answers = asked.filter((f) => f.type !== "consent");

  const emergency =
    entry.emergency_contact_name || entry.emergency_contact_phone
      ? {
          name: entry.emergency_contact_name,
          relation: entry.emergency_contact_relation,
          phone: entry.emergency_contact_phone,
        }
      : null;

  return (
    <div className="overflow-hidden rounded-xl border border-border bg-[var(--surface)]">
      {/* ---- Who ---- */}
      <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 border-b border-border bg-[var(--surface-2)] px-4 py-3">
        <div className="min-w-0">
          <p
            className="truncate text-base font-bold leading-tight"
            style={{ fontFamily: "var(--font-display)" }}
          >
            {entry.full_name}
          </p>
          {/* Email and phone belong with the name, not in a grid cell each:
              they are how you reach this person, which is one fact in two
              parts. The phone is mono because it gets dictated. */}
          <p className="mt-0.5 truncate text-[13px] text-muted-foreground">
            {entry.email}
            {entry.phone && (
              <>
                {" · "}
                <span className="font-mono tabular-nums">
                  {entry.phone}
                </span>
              </>
            )}
          </p>
        </div>
        <p className="text-[13px] text-muted-foreground">
          {entry.category?.name}
          {entry.category && " · "}
          Daftar {fmtDateTime(entry.created_at)}
        </p>
      </div>

      <div className="grid gap-5 px-4 py-4">
        {/* ---- Runner facts the form did not ask for ---- */}
        {(entry.gender || entry.birth_date) && (
          <Pairs>
            <Pair label="Jenis kelamin" value={genderLabel(entry.gender)} />
            <Pair label="Tanggal lahir" value={fmtDate(entry.birth_date)} />
          </Pairs>
        )}

        {/* ---- Emergency contact ----
            The one place this panel raises its voice: a tinted ground, a left
            rule, and the largest type here, because somebody reads this number
            aloud while looking at a phone in bad light.
            The rule is deliberately NOT `--danger`. Red already means "this is
            wrong" further down, on a consent nobody ticked; spending it here
            too would leave two reds competing and make a correctly filled
            emergency contact look like a problem. This block is important, not
            broken, so it takes the brand accent instead. */}
        {emergency && (
          <div className="rounded-lg border-l-[3px] border-l-[var(--brand-600)] bg-[var(--bg-soft)] px-3.5 py-3">
            <h4 className="text-[13px] font-bold">Kontak darurat</h4>
            {/* 15px, the same step every other answer in this panel is set at.
                At 14 it sat one notch below them for no reason anybody could
                see — two adjacent sizes doing one job is a scale with a step
                that carries no meaning. */}
            <p className="mt-1.5 text-[15px]">
              {emergency.name || "Nama tidak diisi"}
              {emergency.relation && (
                <span className="text-muted-foreground">
                  {" "}
                  ({emergency.relation})
                </span>
              )}
            </p>
            {emergency.phone && (
              <p className="mt-0.5 font-mono text-lg font-semibold leading-snug tabular-nums">
                {emergency.phone}
              </p>
            )}
          </div>
        )}

        {/* ---- What the form asked ---- */}
        {answers.length > 0 && (
          <Block title="Data formulir">
            <Pairs>
              {answers.map((f) => (
                <Pair
                  key={f.key}
                  label={f.label}
                  value={formatAnswer(f, entry.custom_fields)}
                  mono={isDigits(formatAnswer(f, entry.custom_fields))}
                />
              ))}
            </Pairs>
          </Block>
        )}

        {/* ---- Consents ----
            A checklist, because that is how they are checked: scan the ticks,
            and only stop at the one that is missing. The statement is the line
            you read and the time is the audit trail beside it — which is the
            opposite of the label-over-value grid these used to sit in. */}
        {consents.length > 0 && (
          <Block title="Persetujuan">
            <ul className="grid gap-1.5">
              {consents.map((f) => (
                <ConsentRow
                  key={f.key}
                  field={f}
                  given={formatAnswer(f, entry.custom_fields)}
                />
              ))}
            </ul>
          </Block>
        )}

        {(entry.documents?.length ?? 0) > 0 && (
          <Block title="Dokumen">
            <DocLinks docs={entry.documents ?? []} slots={schema.team_documents} />
          </Block>
        )}

        {/* ---- Money ----
            A right-aligned ledger rather than more label/value cells: these are
            amounts and reference numbers, and the whole point of a column of
            digits is that it lines up. */}
        <Block title="Pembayaran">
          <dl className="grid gap-1.5 text-sm">
            {/* The snapshot, both halves: the same category costs three
                different amounts over a sale, so the number is only checkable
                with the window named beside it. */}
            <Row label={entry.period_name} value={rupiah(entry.price)} />
            {entry.gross_amount > entry.price && (
              <Row
                label="Biaya layanan & pembayaran"
                value={rupiah(entry.gross_amount - entry.price)}
                muted
              />
            )}
            {entry.gross_amount > entry.price && (
              <Row label="Total dibayar" value={rupiah(entry.gross_amount)} strong />
            )}
            <Row label="Metode" value={methodLabel(entry.payment_method)} plain />
            {entry.paid_at && (
              <Row label="Lunas" value={fmtDateTime(entry.paid_at)} plain />
            )}
            {entry.invoice_number && (
              <Row label="Tagihan" value={entry.invoice_number} />
            )}
            {entry.receipt_number && (
              <Row label="Kwitansi" value={entry.receipt_number} />
            )}
            {entry.bib_number && <Row label="BIB" value={entry.bib_number} />}
            {entry.checked_in_at && (
              <Row label="Check-in" value={fmtDateTime(entry.checked_in_at)} plain />
            )}
          </dl>
        </Block>
      </div>
    </div>
  );
}

/** The chevron button that opens one row's detail. */
export function DetailToggle({
  open,
  onToggle,
}: {
  open: boolean;
  onToggle: () => void;
}) {
  return (
    <button
      type="button"
      onClick={onToggle}
      aria-expanded={open}
      className="inline-flex items-center gap-1 text-xs font-medium text-muted-foreground transition-colors hover:text-foreground"
    >
      {open ? "Tutup" : "Detail"}
      <ChevronDown
        className={cn("h-3.5 w-3.5 transition-transform", open && "rotate-180")}
      />
    </button>
  );
}

/**
 * A titled block. Sentence case, not the tracked-out all-caps eyebrow: all-caps
 * is slower to read, and a heading that shouts over the content it introduces is
 * decoration standing in for hierarchy that weight already carries.
 */
function Block({
  title,
  children,
}: {
  title: string;
  children: React.ReactNode;
}) {
  return (
    <div>
      <h4 className="mb-2 text-[13px] font-bold">{title}</h4>
      {children}
    </div>
  );
}

/**
 * Two columns, not three.
 *
 * Three fitted more cells per row and made every one of them unreadable: a
 * label wrapping to two lines beside a one-word value turned the grid ragged,
 * and "Program Studi" / "Teknik Informatika" is a realistic pair, not an
 * outlier.
 */
function Pairs({ children }: { children: React.ReactNode }) {
  // A <dl>, because the <dt>/<dd> inside it are only a described list when
  // something declares them one — wrapped in a plain <div> they are invalid
  // markup and a screen reader reads twenty labels and twenty values with
  // nothing pairing them up.
  return <dl className="grid gap-x-6 gap-y-3 sm:grid-cols-2">{children}</dl>;
}

/**
 * One label over its answer.
 *
 * The value is the subject and gets the larger, heavier type; the label is a
 * caption. They sit 2px apart so the pair reads as one unit — at the 6px the
 * first pass used, every label floated equidistant from the value above it and
 * the one below, and the eye had to guess which belonged to which.
 */
function Pair({
  label,
  value,
  mono,
}: {
  label: string;
  value?: string | null;
  mono?: boolean;
}) {
  return (
    <div className="min-w-0">
      <dt className="text-[11px] leading-tight text-muted-foreground">{label}</dt>
      <dd
        className={cn(
          "mt-0.5 break-words text-[15px] font-semibold leading-snug",
          mono && "font-mono tabular-nums",
          !value && "font-normal text-muted-foreground",
        )}
      >
        {/* The words, not an em dash. A dash in a column of answers is read as
            a value somebody typed; "Tidak diisi" says which of the two it is,
            and it matters here because the organizer chose to ask. */}
        {value || "Tidak diisi"}
      </dd>
    </div>
  );
}

/**
 * One consent: the statement, ticked or not, with when it was agreed.
 *
 * A missing one is the only thing in this panel that is a problem, so it is the
 * only thing that carries the danger colour — and it says what is wrong rather
 * than leaving a blank where a date should be. The icon is not the only carrier
 * of that: the words differ too, which is what keeps it legible to someone who
 * cannot tell the two colours apart.
 */
function ConsentRow({ field, given }: { field: CustomField; given: string }) {
  const agreed = given !== "";
  // formatAnswer gives "Ya · 18/10/2026 05:04"; the panel has the statement on
  // the same line, so the "Ya" is already said and only the time is new.
  const at = agreed ? given.replace(/^Ya · /, "") : null;

  return (
    <li className="flex items-start gap-2">
      <span
        className={cn(
          "mt-0.5 grid h-4 w-4 shrink-0 place-items-center rounded-full",
          agreed
            ? "bg-[color-mix(in_srgb,var(--success)_16%,transparent)] text-[var(--success)]"
            : "bg-[color-mix(in_srgb,var(--danger)_16%,transparent)] text-[var(--danger)]",
        )}
      >
        {agreed ? (
          <Check className="h-2.5 w-2.5" strokeWidth={3.5} />
        ) : (
          <Minus className="h-2.5 w-2.5" strokeWidth={3.5} />
        )}
      </span>
      <span className="min-w-0 flex-1 text-[13px] leading-snug">
        {field.label}
      </span>
      <span
        className={cn(
          "shrink-0 text-[11px] leading-snug",
          agreed
            ? "font-mono tabular-nums text-muted-foreground"
            : "font-semibold text-[var(--danger)]",
        )}
      >
        {at ?? "Belum disetujui"}
      </span>
    </li>
  );
}

/** One ledger line: label left, figure right, digits aligned. */
function Row({
  label,
  value,
  strong,
  muted,
  plain,
}: {
  label: string;
  value: string | null;
  /** The total, which is what the eye is looking for. */
  strong?: boolean;
  /** A component of the total rather than a figure in its own right. */
  muted?: boolean;
  /** Words, not digits — so no mono and no alignment to keep. */
  plain?: boolean;
}) {
  return (
    <div
      className={cn(
        "flex items-baseline justify-between gap-4",
        strong && "border-t border-border pt-1.5 font-semibold",
        muted && "text-muted-foreground",
      )}
    >
      <dt className={cn(!strong && !muted && "text-muted-foreground")}>
        {label}
      </dt>
      <dd
        className={cn(
          "shrink-0 text-right",
          !plain && "font-mono tabular-nums",
        )}
      >
        {value}
      </dd>
    </div>
  );
}

/**
 * Uploaded files as links, titled by the slot they answer rather than the
 * stored filename — "Bukti Mahasiswa" is what the organizer is looking for,
 * "IMG_2043.webp" is not.
 */
function DocLinks({
  docs,
  slots,
}: {
  docs: NonNullable<RaceEntry["documents"]>;
  slots: DocumentSlot[];
}) {
  return (
    <div className="grid gap-1.5 sm:grid-cols-2">
      {docs.map((d) => {
        const url = /^https?:\/\//.test(d.file_url) ? d.file_url : null;
        const label =
          slots.find((s) => s.key === d.document_type)?.label ??
          d.file_name ??
          d.document_type ??
          "Dokumen";

        return url ? (
          <a
            key={d.id}
            href={url}
            target="_blank"
            rel="noreferrer"
            className="flex items-center gap-2 truncate rounded-md border border-border px-3 py-2 text-[13px] transition-colors hover:border-[var(--brand-500)]"
          >
            <FileText className="h-4 w-4 shrink-0 text-muted-foreground" />
            <span className="min-w-0 flex-1 truncate font-medium">{label}</span>
            <ExternalLink className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
          </a>
        ) : (
          <div
            key={d.id}
            className="flex items-center gap-2 rounded-md border border-border px-3 py-2 text-[13px] text-muted-foreground"
          >
            <FileText className="h-4 w-4 shrink-0" />
            <span className="min-w-0 flex-1 truncate">{label}</span>
            <span className="shrink-0 text-[11px]">tidak tersedia</span>
          </div>
        );
      })}
    </div>
  );
}

/**
 * Whether a value is read digit by digit — a student number, a phone, a jersey
 * size is not.
 *
 * Mono is applied for that reason alone, not as a decorative face for small
 * data labels: these get compared against a card and dictated down a phone, and
 * proportional digits make 1/7 and 0/O exactly the comparison that fails.
 */
function isDigits(value: string): boolean {
  return /^[\d\s+().-]{4,}$/.test(value);
}

/**
 * "L"/"P" as words. The column is free text on purpose — an event classifying
 * by age group has no use for it — so anything else is printed as it was typed
 * rather than mapped to a guess.
 */
function genderLabel(value: string | null): string | null {
  if (!value) return null;

  return { L: "Laki-laki", P: "Perempuan" }[value.toUpperCase()] ?? value;
}

function methodLabel(method: string | null | undefined): string {
  return method === "manual" ? "Transfer manual" : "Pembayaran online";
}

function fmtDate(value: string | null): string | null {
  if (!value) return null;

  return new Date(value).toLocaleDateString("id-ID", {
    day: "2-digit",
    month: "long",
    year: "numeric",
  });
}

function fmtDateTime(value: string | null): string | null {
  if (!value) return null;

  return new Date(value).toLocaleString("id-ID", {
    day: "2-digit",
    month: "short",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}
