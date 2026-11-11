/**
 * How the ID card and certificate editors read a placeable field key.
 *
 * Mirror of App\Support\TemplateFields — the server owns the catalogue, and
 * both editors ask it. What lives here is only what a *client* needs that no
 * payload can answer: how to label a key the current catalogue does not
 * contain, and how to group the picker.
 *
 * That gap is not an edge case, it is the shape of the feature. Templates are
 * org-scoped rows reused season after season while a custom field belongs to
 * one event's registration form, so an editor opened on event B will routinely
 * be holding a template that places a question only event A asked. The key is
 * still valid and still prints (blank, for B) — what it has no label for is the
 * dropdown, and printing `custom:player:asal_sekolah` raw there reads as a bug.
 */

import type { CertificateFieldDef, IdCardFieldDef } from "@/types/api";

type FieldDef = IdCardFieldDef | CertificateFieldDef;

/** Prefix the API namespaces a registration question under. */
export const CUSTOM_PREFIX = "custom:";

/** Heading for keys whose question is not in the catalogue being shown. */
export const OTHER_EVENT_GROUP = "Dari event lain";

export const isCustomFieldKey = (key: string) => key.startsWith(CUSTOM_PREFIX);

/**
 * A readable name for a key the catalogue does not describe.
 *
 * The question's own key is the best thing available — the organizer typed it,
 * so "asal_sekolah" is something they recognise — and the section it came from
 * is what disambiguates the team copy from the player copy of a question keyed
 * the same in both. Underscores become spaces because that is the only part of
 * the key that is punctuation rather than a word.
 */
export function fallbackFieldLabel(key: string): string {
  if (!isCustomFieldKey(key)) return key;

  const [section, ...rest] = key.slice(CUSTOM_PREFIX.length).split(":");
  const question = rest.join(":").replace(/_/g, " ");

  if (!question) return key;

  const where =
    section === "player" ? "pemain" : section === "official" ? "ofisial" : "tim";

  return `${question} (${where})`;
}

/**
 * The label the editor prints for a placed field, whether or not the catalogue
 * currently loaded knows it.
 */
export function fieldLabel(defs: FieldDef[], key: string): string {
  return defs.find((d) => d.key === key)?.label ?? fallbackFieldLabel(key);
}

/**
 * The picker's options, grouped, in catalogue order.
 *
 * Grouping is read off `group`, which the server sends, rather than derived
 * from the key's prefix here: deriving it would be a second reader of a rule
 * only TemplateFields states, and the two would drift the first time a section
 * is added.
 */
export function groupFields<T extends FieldDef>(defs: T[]): Array<{ group: string; defs: T[] }> {
  const out: Array<{ group: string; defs: T[] }> = [];

  for (const def of defs) {
    const group = def.group || OTHER_EVENT_GROUP;
    const last = out.find((g) => g.group === group);

    if (last) last.defs.push(def);
    else out.push({ group, defs: [def] });
  }

  return out;
}
