"use client";

import * as React from "react";
import { X } from "lucide-react";

import { cn } from "@/lib/utils";

/**
 * A list of short strings typed one at a time, each shown as a removable chip.
 *
 * Replaces the "one per line" textarea that used to hold a select's options.
 * That shape had a failure the chips make structurally impossible: the list
 * lived as text, so the component had to decide on every keystroke whether a
 * blank line was a value — and filtering them ate the newline the organizer
 * had just pressed, leaving a select that could never hold more than one
 * option. Here a value exists only once it has been committed, so there is no
 * half-typed state in the array at all and the caller never has to clean it.
 *
 * Deliberately not a library. select2/react-select bring a popover, async
 * loading, and a controlled-menu lifecycle for a field whose entire job is
 * "collect a few words"; the one behaviour borrowed from them is the part
 * organizers recognise — type, Enter, chip.
 */
export interface TagInputProps {
  value: string[];
  onChange: (next: string[]) => void;
  placeholder?: string;
  /** Refuses further entries once reached. Mirrors the API's `max:50`. */
  maxTags?: number;
  /** Per-entry cap. Mirrors the API's `options.*` `max:100`. */
  maxLength?: number;
  id?: string;
  "aria-invalid"?: boolean;
  "aria-label"?: string;
  className?: string;
  disabled?: boolean;
}

export function TagInput({
  value,
  onChange,
  placeholder,
  maxTags = 50,
  maxLength = 100,
  id,
  "aria-invalid": invalid,
  "aria-label": ariaLabel,
  className,
  disabled,
}: TagInputProps) {
  const [draft, setDraft] = React.useState("");
  const inputRef = React.useRef<HTMLInputElement>(null);

  const full = value.length >= maxTags;

  /**
   * Commit one or more entries.
   *
   * Takes a raw string and splits it, so a paste of "Putra, Putri" or of a
   * column copied out of a spreadsheet lands as several chips rather than one
   * chip containing commas — the one way organizers actually produce these
   * lists in bulk. Duplicates are dropped silently: the API rejects a select
   * whose options repeat, and a chip that appears twice reads as a glitch
   * rather than as a rule.
   */
  const commit = (raw: string) => {
    const parts = raw
      .split(/[\n,;]/)
      .map((p) => p.trim().slice(0, maxLength))
      .filter(Boolean);

    if (parts.length === 0) return;

    const next = [...value];
    for (const p of parts) {
      if (next.length >= maxTags) break;
      if (!next.includes(p)) next.push(p);
    }

    if (next.length !== value.length) onChange(next);
    setDraft("");
  };

  const removeAt = (i: number) => onChange(value.filter((_, idx) => idx !== i));

  const onKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    // Enter and comma both commit. Enter is the gesture every tag field has;
    // the comma is what someone typing a list out of habit reaches for, and
    // without it they end up with one chip reading "Putra, Putri".
    if (e.key === "Enter" || e.key === ",") {
      e.preventDefault();
      commit(draft);
      return;
    }

    // Backspace on an empty box eats the last chip. The × is the discoverable
    // control; this is the one that makes fixing a typo one key instead of a
    // reach for the mouse.
    if (e.key === "Backspace" && draft === "" && value.length > 0) {
      e.preventDefault();
      removeAt(value.length - 1);
    }
  };

  return (
    <div
      className={cn(
        "flex min-h-10 w-full flex-wrap items-center gap-1.5 rounded-md border border-input bg-background px-2 py-1.5 text-sm ring-offset-background focus-within:ring-2 focus-within:ring-ring focus-within:ring-offset-2",
        invalid && "border-destructive",
        disabled && "cursor-not-allowed opacity-50",
        className,
      )}
      // Clicking the padding focuses the box, so the whole control behaves as
      // the single input it looks like.
      onClick={() => inputRef.current?.focus()}
    >
      {value.map((tag, i) => (
        <span
          key={`${tag}-${i}`}
          className="inline-flex max-w-full items-center gap-1 rounded-full bg-[var(--tint)] py-0.5 pl-2.5 pr-1 text-xs font-semibold text-[var(--brand-600)]"
        >
          <span className="truncate">{tag}</span>
          <button
            type="button"
            disabled={disabled}
            onClick={(e) => {
              e.stopPropagation();
              removeAt(i);
            }}
            className="rounded-full p-0.5 hover:bg-[color-mix(in_srgb,var(--brand-600)_18%,transparent)] disabled:pointer-events-none"
            aria-label={`Hapus pilihan ${tag}`}
          >
            <X className="h-3 w-3" />
          </button>
        </span>
      ))}

      <input
        ref={inputRef}
        id={id}
        value={draft}
        disabled={disabled || full}
        maxLength={maxLength}
        aria-label={ariaLabel}
        aria-invalid={invalid}
        placeholder={
          full ? `Maksimal ${maxTags} pilihan` : value.length === 0 ? placeholder : "Tambah pilihan…"
        }
        className="min-w-28 flex-1 bg-transparent px-1 py-0.5 outline-none placeholder:text-muted-foreground disabled:cursor-not-allowed"
        onChange={(e) => {
          // A paste arrives as one change, not as keystrokes, so the split has
          // to happen here too — otherwise pasted text sits in the draft until
          // Enter and the separators inside it are never seen.
          if (/[\n,;]/.test(e.target.value)) {
            commit(e.target.value);
            return;
          }
          setDraft(e.target.value);
        }}
        onKeyDown={onKeyDown}
        // Leaving the field commits what is in it. A typed value abandoned on
        // blur is the one that disappears at save with nothing to explain it.
        onBlur={() => commit(draft)}
      />
    </div>
  );
}
