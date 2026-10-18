"use client";

import { useState } from "react";
import { ImageIcon } from "lucide-react";

import type { CustomField, CustomFieldAnswers } from "@/lib/registration-form";
import {
  CONSENT_ON,
  isConsentGiven,
  visibleFields,
} from "@/lib/registration-form";
import { DatePicker } from "@/components/ui/date-picker";
import { Dialog, DialogContent, DialogHeader } from "@/components/ui/dialog";
import { InfoHint } from "@/components/ui/info-hint";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";

/**
 * The organizer's guidance picture, behind a link that opens it full size.
 *
 * Not inside the InfoHint beside it, and not inline under the field either. A
 * size chart is a table of numbers somebody reads a row out of — in a hover
 * panel it is a thumbnail of a table, and inline it pushed every neighbouring
 * input onto a different baseline, which is what knocked the two columns out
 * of alignment in the first place. A link costs one deliberate click and then
 * gives the picture the whole screen, which is the only size at which it does
 * its job.
 *
 * **It renders in the label row, beside the hint marker** — the one place in
 * the cell that cannot change its height. Under the input it was a second line
 * only some fields had, and that is the same misalignment in a new costume:
 * the field with a chart stood a line taller than the one beside it. An icon
 * the size of the marker next to it keeps every label row identical.
 *
 * The dialog is the app's own, so Escape, the overlay click and focus return
 * all behave the way they do everywhere else.
 */
function GuidanceImage({
  url,
  label,
  /**
   * `"marker"` (the default) renders a bare icon for the label row, where the
   * cell's height is fixed. `"link"` spells the words out, for a consent — a
   * lone icon dangling under a sentence of terms has nothing beside it to
   * explain what it is, and consents stack one per row so there is no height
   * to protect.
   */
  variant = "marker",
}: {
  url: string;
  label: string;
  variant?: "marker" | "link";
}) {
  const [open, setOpen] = useState(false);

  return (
    <>
      <button
        // Load-bearing: this renders inside <form>, and a bare <button>
        // defaults to submit — opening the chart would send the entry.
        type="button"
        onClick={() => setOpen(true)}
        // The words live in the accessible name either way: beside a label
        // "Lihat gambar panduan" is wider than most labels are and would wrap
        // the very row it is there to keep to one line. In marker form it
        // matches the InfoHint beside it exactly, so the two read as one pair
        // of affordances rather than two designs.
        aria-label={`Lihat gambar panduan ${label}`}
        title={variant === "marker" ? `Lihat gambar panduan ${label}` : undefined}
        // The marker copies InfoHint down to the invisible 44px hit area: two
        // icons sitting side by side on one label row, one of them comfortable
        // to tap and the other not, is worse than either choice made twice.
        // The link form already clears it on its own height plus its words.
        className={
          variant === "marker"
            ? "relative text-muted-foreground transition-colors after:absolute after:left-1/2 after:top-1/2 after:h-11 after:w-11 after:-translate-x-1/2 after:-translate-y-1/2 after:content-[''] hover:text-foreground focus-visible:text-foreground focus-visible:outline-none"
            : "inline-flex w-fit items-center gap-1.5 py-1 text-xs font-medium text-[var(--brand-600)] underline underline-offset-2 hover:text-[var(--brand-700)]"
        }
      >
        <ImageIcon className={variant === "marker" ? "h-4 w-4" : "h-3.5 w-3.5"} />
        {variant === "link" && "Lihat gambar panduan"}
      </button>

      {/* `placement="sheet"` so a phone gets the bottom sheet every other
          dialog in the app gives it, with the centred panel back from `sm:`
          up. */}
      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent placement="sheet" className="max-w-3xl">
          {/* `title` is content, not an element: DialogHeader already wraps it
              in Radix's Title, and nesting a second one is a Title inside a
              Title. */}
          <DialogHeader title={label} description="Ketuk gambar untuk memperbesar." />
          <div className="overflow-auto p-5">
            {/* Wrapped in a link so a phone can open the file itself and use
                the OS's own pinch-zoom: a size chart is a grid of small
                numbers, and `max-h` fitted to the sheet is exactly the size at
                which it stops being readable. `rel` because `target` opens a
                new browsing context.

                `alt` is the field's label so a blocked or broken image still
                says what it belonged to, the same call the email header
                makes. */}
            <a href={url} target="_blank" rel="noopener noreferrer">
              {/* eslint-disable-next-line @next/next/no-img-element */}
              <img
                src={url}
                alt={`Panduan ${label}`}
                className="mx-auto w-auto max-w-full rounded-lg"
              />
            </a>
          </div>
        </DialogContent>
      </Dialog>
    </>
  );
}

/**
 * The custom fields an event defined, rendered from the schema.
 *
 * Renders nothing when the list is empty — the same reason DocumentUploadFields
 * does: an event that asks for nothing extra shows nothing extra, without any
 * caller needing a conditional of its own.
 *
 * `idPrefix` keeps labels bound to their own input when several of these are on
 * one page (the team's fields plus one set per player row).
 *
 * Emits bare cells into the CALLER's grid rather than a grid of its own. Nested,
 * a lone extra field started a fresh two-column row and sat half-width beside a
 * gap, while the built-in fields it belongs with flowed in the row above — the
 * fields looked like a different form. `long_text` spans two columns, which only
 * means anything if the columns are the caller's.
 *
 * Conditional fields are filtered through `visibleFields`, the one reader of
 * the rule — the same function `missingFor` asks, so a field this never renders
 * is never counted missing either. The answer a hidden field already holds is
 * left in state untouched: the server drops it on write, and clearing it here
 * would lose what somebody typed the moment they flicked the trigger to look at
 * the other branch.
 */
export function CustomFieldEditor({
  fields,
  value,
  onChange,
  disabled,
  idPrefix,
  fieldErrors,
}: {
  fields: CustomField[];
  value: CustomFieldAnswers;
  onChange: (answers: CustomFieldAnswers) => void;
  disabled?: boolean;
  idPrefix: string;
  /** Server-side errors keyed by field key, shown inline like the rest of the form. */
  fieldErrors?: Record<string, string>;
}) {
  if (fields.length === 0) return null;

  const set = (key: string, v: string) => onChange({ ...value, [key]: v });
  const shown = visibleFields(fields, value);

  if (shown.length === 0) return null;

  /**
   * Consents are gathered to the end and fenced off, rather than left wherever
   * they were authored.
   *
   * They are a different kind of thing from the fields around them: a shirt
   * size is information about the entrant, while "saya memahami risiko lomba"
   * is a statement they are making. Interleaved, four sentences of agreement sit
   * between dropdowns and read as more form to fill rather than as terms —
   * which is the one thing on a race entry nobody should skim.
   *
   * Reordering is safe because the group has no internal order to lose: each
   * consent stands alone, unlike a `visible_if` field that must follow its
   * trigger. Answers are untouched — this is purely where they render.
   */
  const inputs = shown.filter((f) => f.type !== "consent");
  const consents = shown.filter((f) => f.type === "consent");

  return (
    <>
      {inputs.map((field) => {
        const id = `${idPrefix}-${field.key}`;
        const answer = value[field.key] ?? "";
        const error = fieldErrors?.[field.key];
        /**
         * The organizer's wording, behind a marker beside the label rather
         * than in a paragraph under it.
         *
         * In the flow it pushed the input down by however many lines it
         * happened to wrap to, so two fields side by side — one with two lines
         * of help, one with three — put their inputs on different baselines and
         * the row stopped reading as a row. The marker keeps every label one
         * line tall, which is what lets the columns align at all.
         *
         * Text only. A picture gets its own link below — see GuidanceImage.
         */
        const guidance = field.help_text && (
          <InfoHint label={`Penjelasan ${field.label}`}>{field.help_text}</InfoHint>
        );

        return (
          <div
            key={field.key}
            className={`grid gap-2 ${field.type === "long_text" ? "sm:col-span-2" : ""}`}
          >
            {/* The markers sit beside the Label, never inside it: a <label>
                may not wrap interactive content, and nested there a tap on an
                icon would also focus the input behind it. Same arrangement the
                organizer's own event form uses.

                `gap-3` between the two markers, wider than the `gap-1.5` that
                follows the label: each carries an invisible 44px tap target,
                and at 6px apart those overlap — the finger aiming at the chart
                would land on whichever target the hit test reached first. The
                label needs no such room; it is not a button. */}
            <div className="flex items-center gap-1.5">
              <Label htmlFor={id}>
                {field.label}
                {field.required && <span className="ml-1 text-destructive">*</span>}
              </Label>
              <span className="flex items-center gap-3">
                {guidance}
                {field.help_image_url && (
                  <GuidanceImage url={field.help_image_url} label={field.label} />
                )}
              </span>
            </div>

            {field.type === "long_text" ? (
              <Textarea
                id={id}
                rows={3}
                value={answer}
                disabled={disabled}
                aria-invalid={!!error}
                onChange={(e) => set(field.key, e.target.value)}
              />
            ) : field.type === "select" ? (
              <Select
                id={id}
                value={answer}
                disabled={disabled}
                aria-invalid={!!error}
                onChange={(e) => set(field.key, e.target.value)}
              >
                <option value="">Pilih…</option>
                {field.options.map((opt) => (
                  <option key={opt} value={opt}>
                    {opt}
                  </option>
                ))}
              </Select>
            ) : field.type === "date" ? (
              <DatePicker
                id={id}
                value={answer}
                disabled={disabled}
                aria-invalid={!!error}
                onChange={(v) => set(field.key, v)}
              />
            ) : (
              <Input
                id={id}
                value={answer}
                disabled={disabled}
                aria-invalid={!!error}
                onChange={(e) => set(field.key, e.target.value)}
              />
            )}

            {error && <p className="text-xs text-destructive">{error}</p>}
          </div>
        );
      })}

      {/* The statements, fenced off from the questions above. A consent reads
          label-beside-checkbox rather than label-above-input: the <Label
          htmlFor> header used for every field above would put the sentence over
          an unlabelled tickbox, and the statement would stop being the thing
          the person is agreeing to.

          `sm:col-span-2` so the group stays full width in a caller whose grid
          has two columns — a sentence of terms split into a 50% gutter is the
          same misreading in another form. */}
      {consents.length > 0 && (
        <div className="grid gap-3 rounded-lg border border-border p-4 sm:col-span-2">
          {consents.map((field) => {
            const id = `${idPrefix}-${field.key}`;
            const answer = value[field.key] ?? "";
            const error = fieldErrors?.[field.key];

            return (
              <div key={field.key} className="grid gap-1.5">
                <label className="flex cursor-pointer items-start gap-2.5 text-sm">
                  <input
                    id={id}
                    type="checkbox"
                    className="mt-0.5 h-4 w-4 shrink-0 accent-[var(--brand-600)]"
                    checked={isConsentGiven(answer)}
                    disabled={disabled}
                    aria-invalid={!!error}
                    /* Only whether it is on. The instant is the server's to
                       write — see CONSENT_ON. */
                    onChange={(e) => set(field.key, e.target.checked ? CONSENT_ON : "")}
                  />
                  <span>
                    {field.label}
                    {field.required && <span className="ml-1 text-destructive">*</span>}
                  </span>
                </label>

                {/* Spelled out in the flow, NOT behind a marker the way every
                    field above now is — and the two cases differ in what the
                    text is for. A field's help explains how to answer, so
                    hiding it costs a tap. A consent's help is part of the terms
                    being agreed to ("saya tidak akan menuntut penyelenggara
                    atas cedera…"), and terms somebody has to go looking for are
                    not terms they have read.

                    Nothing here has to line up, either: consents are stacked
                    one per row, so the wrapping that knocked the field columns
                    out of alignment does not arise.

                    Indented to the sentence's own text — the checkbox plus its
                    gap — so it reads as part of the statement above it. */}
                {(field.help_text || field.help_image_url) && (
                  <div className="grid gap-1.5 pl-[1.625rem]">
                    {field.help_text && (
                      <p className="text-xs leading-relaxed text-muted-foreground">
                        {field.help_text}
                      </p>
                    )}
                    {/* The picture goes behind the same link as everywhere
                        else, even though the words beside it are spelled out:
                        the words ARE the terms, while a picture attached to a
                        consent is a reference — and inline it buries the next
                        statement a screen further down. */}
                    {field.help_image_url && (
                      <GuidanceImage
                        url={field.help_image_url}
                        label={field.label}
                        variant="link"
                      />
                    )}
                  </div>
                )}

                {error && <p className="text-xs text-destructive">{error}</p>}
              </div>
            );
          })}
        </div>
      )}
    </>
  );
}
