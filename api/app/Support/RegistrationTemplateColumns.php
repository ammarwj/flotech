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
 * Deliberately excludes custom fields and documents — the user narrowed
 * scope to core fields only; those stay manual via "Ubah Tim" after import.
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

        $columns = [];

        if (! $isFixed) {
            $columns[] = ['key' => 'team_name', 'label' => 'Nama Tim'];
        }

        $columns[] = ['key' => 'contact_name', 'label' => 'Nama Kontak'];
        $columns[] = ['key' => 'contact_phone', 'label' => 'No. HP Kontak'];

        $playerCount = $isFixed ? $rosterSize : self::MAX_PLAYERS;

        for ($i = 1; $i <= $playerCount; $i++) {
            $columns[] = ['key' => "player_{$i}_name", 'label' => "Pemain {$i} - Nama"];

            if (! $isFixed) {
                $columns[] = ['key' => "player_{$i}_jersey", 'label' => "Pemain {$i} - No. Punggung"];
            }

            $columns[] = ['key' => "player_{$i}_position", 'label' => "Pemain {$i} - Posisi"];
        }

        if (! $isFixed) {
            for ($i = 1; $i <= self::MAX_OFFICIALS; $i++) {
                $columns[] = ['key' => "official_{$i}_name", 'label' => "Ofisial {$i} - Nama"];
                $columns[] = ['key' => "official_{$i}_role", 'label' => "Ofisial {$i} - Peran"];
            }
        }

        return $columns;
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
