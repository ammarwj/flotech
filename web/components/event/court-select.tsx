"use client";

import { MapPin } from "lucide-react";

import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import { cn } from "@/lib/utils";

/**
 * Picks a match's court. When the event defines a court list the organizer
 * chooses one from a dropdown; otherwise it falls back to the old free-text
 * input so events without a list keep working exactly as before.
 *
 * A value the list no longer contains (e.g. an old free-text venue) is kept as
 * an extra option so saving the schedule never silently drops it.
 */
export function CourtSelect({
  value,
  onChange,
  courts,
  id,
  className,
  ariaLabel = "Lapangan pertandingan",
  placeholder = "Lokasi / lapangan",
}: {
  value: string | null;
  onChange: (value: string | null) => void;
  courts: string[];
  id?: string;
  className?: string;
  ariaLabel?: string;
  placeholder?: string;
}) {
  if (courts.length === 0) {
    return (
      <div className="relative">
        <MapPin className="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
        <Input
          id={id}
          value={value ?? ""}
          onChange={(e) => onChange(e.target.value || null)}
          placeholder={placeholder}
          className={cn("pl-8", className)}
          aria-label={ariaLabel}
        />
      </div>
    );
  }

  // Preserve a stored venue that isn't among the defined courts.
  const orphan = value && !courts.includes(value) ? value : null;

  return (
    <Select
      id={id}
      value={value ?? ""}
      onChange={(e) => onChange(e.target.value || null)}
      className={className}
      aria-label={ariaLabel}
    >
      <option value="">— pilih lapangan —</option>
      {courts.map((c) => (
        <option key={c} value={c}>
          {c}
        </option>
      ))}
      {orphan && <option value={orphan}>{orphan} (lain)</option>}
    </Select>
  );
}
