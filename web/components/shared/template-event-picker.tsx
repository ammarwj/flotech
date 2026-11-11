"use client";

import { useQuery } from "@tanstack/react-query";

import { getEvents } from "@/lib/api/events";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";

/**
 * Which event's registration questions the template editor offers to place.
 *
 * It is a LENS, not an owner, and the whole feature rests on that distinction.
 * Templates are org-scoped rows (`id_card_templates.organization_id`, no
 * `event_id`) reused season after season, while a custom field belongs to one
 * event's `registration_form`. So this picker widens the field list; it does
 * not narrow what the template may be used for. A template that places a
 * question only one event asked stays valid everywhere and simply renders
 * blank elsewhere, exactly as an unanswered optional field already does.
 *
 * Saying that out loud, in the hint, is the point of the component: a dropdown
 * labelled "Event" on a form that saves an org-scoped row reads like it is
 * choosing the template's owner, and an organizer who believes that will build
 * one template per event.
 *
 * Defaults to the newest event rather than to nothing. Empty would be the
 * honest "no event chosen", but it is also a picker that must be touched before
 * the thing it exists for appears — and the questions it unlocks are precisely
 * the ones nobody knows to look for.
 */
export function TemplateEventPicker({
  orgId,
  value,
  onChange,
}: {
  orgId: string;
  /** Empty string = fixed catalogue only. */
  value: string;
  onChange: (eventId: string) => void;
}) {
  const eventsQuery = useQuery({
    queryKey: ["events", orgId],
    queryFn: () => getEvents(orgId),
    enabled: !!orgId,
  });

  const events = eventsQuery.data ?? [];

  // Nothing to choose between and nothing to explain: an organization with no
  // events has no custom questions either, so the picker would be a disabled
  // control over an empty list.
  if (!eventsQuery.isLoading && events.length === 0) return null;

  return (
    <div className="sm:col-span-2">
      <Label htmlFor="field-source-event">Ambil field dari formulir event</Label>
      <Select
        id="field-source-event"
        value={value}
        onChange={(e) => onChange(e.target.value)}
        disabled={eventsQuery.isLoading}
        className="mt-1.5"
      >
        <option value="">— data standar saja —</option>
        {events.map((event) => (
          <option key={event.id} value={event.id}>
            {event.name}
          </option>
        ))}
      </Select>
      <p className="mt-1.5 text-xs text-muted-foreground">
        Menentukan pertanyaan formulir mana yang bisa ditempatkan di desain — bukan event mana
        yang memakai template ini. Template tetap bisa dipakai di event mana pun; field yang
        tidak ditanyakan event tersebut akan tercetak kosong.
      </p>
    </div>
  );
}
