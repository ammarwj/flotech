<?php

namespace App\Support;

use App\Models\EventCategory;

/**
 * The one place that defines the import/export template's column layout.
 *
 * Export and import both read this list **by index**, never by header name:
 * maatwebsite/excel auto-slugifies header rows, and that slug is unreliable
 * for a header like "Pemain 1 - Nama" (numbers/dashes). The human-readable
 * label is still what's written to row 1, but it is decoration — the column
 * position is what carries meaning. Keeping both sides reading this same
 * list is what makes them impossible to drift apart, the same reasoning
 * CLAUDE.md documents for RegistrationForm/PaymentRails.
 *
 * Custom fields from `events.registration_form` ride along too, one column
 * per field per team/player-slot/official-slot, keyed `cf_team_{key}`,
 * `cf_player_{i}_{key}`, `cf_official_{i}_{key}` — a prefix none of the
 * hardcoded keys above can ever produce, so the two can't collide and no
 * parsing is needed to tell them apart. An event that defines none keeps the
 * exact column count it had before this existed — each `foreach` over a
 * RegistrationForm section below emits nothing when that section is empty —
 * the same "absent is off" rule RegistrationForm documents for its own flags.
 *
 * A field hidden behind `visible_if` still gets a column: there is no
 * "this row's answers" to evaluate the condition against at template-build
 * time, only at import time per row — see TeamRosterImport, which routes
 * every answer through RegistrationForm::isVisible()/cleanAnswers() exactly
 * like the manual-entry path, so a hidden field's cell is read but dropped
 * the same way a stale tab's answer would be.
 *
 * Documents still don't ride along — a file can't sit in a spreadsheet cell,
 * and that's unrelated to why custom fields were excluded before.
 */
final class RegistrationTemplateColumns
{
    /** Big enough for the largest squad sport (football ~25). */
    public const MAX_PLAYERS = 30;

    public const MAX_OFFICIALS = 5;

    /**
     * @return list<array{key: string, label: string}> column order = template
     *   column order
     */
    public static function forCategory(EventCategory $category): array
    {
        $rosterSize = $category->rosterSize();
        $isFixed = $rosterSize !== null;

        // The three core headers follow whatever the organizer renamed them to
        // on the registration form. The KEYS below do not — the import reads
        // this list by index and zips the keys onto the row, so a key that
        // moved with a label would rename a `teams` column by accident.
        //
        // The header row is matched case-insensitively on import
        // (TeamRosterImport::headerMatches), which is what keeps a template
        // downloaded before a rename from being silently zipped against the
        // wrong columns while still telling the organizer plainly to re-download.
        $form = RegistrationForm::forEvent($category->event);

        $columns = [];

        if (! $isFixed) {
            $columns[] = ['key' => 'team_name', 'label' => $form->label('team_name')];
        }

        $columns[] = ['key' => 'contact_name', 'label' => $form->label('contact_name')];
        $columns[] = ['key' => 'contact_phone', 'label' => $form->label('contact_phone')];

        foreach ($form->teamFields as $field) {
            $columns[] = ['key' => "cf_team_{$field['key']}", 'label' => self::fieldLabel($field)];
        }

        $playerCount = $isFixed ? $rosterSize : self::MAX_PLAYERS;

        for ($i = 1; $i <= $playerCount; $i++) {
            $columns[] = ['key' => "player_{$i}_name", 'label' => "Pemain {$i} - Nama"];

            // Jersey number and position are both squad-only — a fixed-roster
            // category (single/double) IS its participant_type, so "posisi"
            // is the kategori itself ("Tunggal"/"Ganda"), not a per-player
            // choice. The public roster form already hides both for a fixed
            // roster (RosterEditor: `squadFields` / `!fixed`); this used to
            // gate only the jersey column, leaving "Posisi" in the template
            // for sports whose catalog happens to be non-empty (badminton's
            // is singles/doubles) even though nothing ever reads it there.
            if (! $isFixed) {
                $columns[] = ['key' => "player_{$i}_jersey", 'label' => "Pemain {$i} - No. Punggung"];
                $columns[] = ['key' => "player_{$i}_position", 'label' => "Pemain {$i} - Posisi"];
            }

            foreach ($form->playerFields as $field) {
                $columns[] = ['key' => "cf_player_{$i}_{$field['key']}", 'label' => "Pemain {$i} - ".self::fieldLabel($field)];
            }
        }

        if (! $isFixed) {
            for ($i = 1; $i <= self::MAX_OFFICIALS; $i++) {
                $columns[] = ['key' => "official_{$i}_name", 'label' => "Ofisial {$i} - Nama"];
                $columns[] = ['key' => "official_{$i}_role", 'label' => "Ofisial {$i} - Peran"];

                foreach ($form->officialFields as $field) {
                    $columns[] = ['key' => "cf_official_{$i}_{$field['key']}", 'label' => "Ofisial {$i} - ".self::fieldLabel($field)];
                }
            }
        }

        return $columns;
    }

    /**
     * The header for one custom field's column — the label the organizer
     * wrote, plus a hint of what the cell expects. `select` spells out its
     * options right in the header rather than only in the guide sheet: the
     * options are usually few and short, and an organizer typing into column
     * AZ shouldn't have to scroll back to a sheet two tabs away to see them.
     * `date` and `consent` get a one-word format hint instead, since there's
     * nothing to enumerate.
     *
     * @param  array<string, mixed>  $field
     */
    private static function fieldLabel(array $field): string
    {
        $suffix = match ($field['type']) {
            'select' => ' ('.implode('/', $field['options']).')',
            'date' => ' (YYYY-MM-DD)',
            'consent' => ' (Ya/Tidak)',
            default => '',
        };

        $required = $field['required'] ? '*' : '';

        return "{$field['label']}{$required}{$suffix}";
    }

    /** @return list<string> */
    public static function headings(EventCategory $category): array
    {
        return array_column(self::forCategory($category), 'label');
    }

    /** @return list<string> */
    public static function keys(EventCategory $category): array
    {
        return array_column(self::forCategory($category), 'key');
    }
}
