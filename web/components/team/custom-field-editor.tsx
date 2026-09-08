"use client";

import type { CustomField, CustomFieldAnswers } from "@/lib/registration-form";
import { DatePicker } from "@/components/ui/date-picker";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";

/**
 * The custom fields an event defined, rendered from the schema.
 *
 * Renders nothing when the list is empty — the same reason DocumentUploadFields
 * does: an event that asks for nothing extra shows nothing extra, without any
 * caller needing a conditional of its own.
 *
 * `idPrefix` keeps labels bound to their own input when several of these are on
 * one page (the team's fields plus one set per player row).
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

  return (
    <div className="grid gap-4 sm:grid-cols-2">
      {fields.map((field) => {
        const id = `${idPrefix}-${field.key}`;
        const answer = value[field.key] ?? "";
        const error = fieldErrors?.[field.key];

        return (
          <div
            key={field.key}
            className={`grid gap-2 ${field.type === "long_text" ? "sm:col-span-2" : ""}`}
          >
            <Label htmlFor={id}>
              {field.label}
              {field.required && <span className="ml-1 text-destructive">*</span>}
            </Label>

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
    </div>
  );
}
