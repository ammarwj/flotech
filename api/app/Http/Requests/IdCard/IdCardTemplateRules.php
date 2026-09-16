<?php

namespace App\Http\Requests\IdCard;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validation for an ID card template's payload, shared by the Store and Update
 * requests — the same reason TeamPayloadRules and EventCategoryRules exist.
 *
 * Here the sharing buys more than tidiness. Half the rules cannot be expressed
 * as a wildcard at all and live in an `after()` closure instead; two hand-kept
 * copies of that closure would be two versions of "a photo needs a box", and
 * the pair that drifted would let a template through that the renderer then has
 * to guess about.
 */
class IdCardTemplateRules
{
    /**
     * @param  string  $presence  'required' (store: the whole template arrives
     *                            at once) or 'sometimes' (update: an omitted
     *                            key means "leave it alone")
     * @return array<string, mixed>
     */
    public static function make(string $presence): array
    {
        $required = $presence === 'required' ? ['required'] : ['sometimes', 'required'];

        return [
            'name' => [...$required, 'string', 'max:120'],
            'background_url' => [...$required, 'string', 'max:2048'],

            // Between a stamp and a poster. Deliberately wide: the organizer
            // types two numbers, and the CR80/A6/A7 presets are a frontend
            // affordance that never reaches the API as an enum.
            'width_mm' => [...$required, 'numeric', 'between:20,400'],
            'height_mm' => [...$required, 'numeric', 'between:20,400'],

            // Twelve is past anything a card the size of a palm can hold; the
            // cap is here so a runaway client cannot make the renderer loop.
            'fields' => [...$required, 'array', 'min:1', 'max:12'],

            // Only keys the renderer knows how to fill, so a template can never
            // place a field that would print blank.
            'fields.*.key' => ['required', 'string', Rule::in(array_keys((array) config('id_card.fields')))],
            'fields.*.x' => ['required', 'numeric', 'between:0,100'],
            'fields.*.y' => ['required', 'numeric', 'between:0,100'],

            // Text only. `size` is millimetres — see config/id_card.php.
            'fields.*.size' => ['nullable', 'numeric', 'between:1,40'],
            'fields.*.color' => ['nullable', 'string', 'max:9'],
            'fields.*.align' => ['nullable', Rule::in(['left', 'center', 'right'])],
            'fields.*.bold' => ['nullable', 'boolean'],
            'fields.*.uppercase' => ['nullable', 'boolean'],
            'fields.*.wrap' => ['nullable', 'numeric', 'between:10,100'],

            // Photo only.
            'fields.*.w' => ['nullable', 'numeric', 'between:1,100'],
            'fields.*.h' => ['nullable', 'numeric', 'between:1,100'],
            'fields.*.fit' => ['nullable', Rule::in(['cover', 'contain'])],
            'fields.*.radius' => ['nullable', 'numeric', 'between:0,50'],
        ];
    }

    /**
     * The rules a flat wildcard cannot state.
     *
     * Two field shapes share one array, so `fields.*.size` has to be nullable
     * even though a text field without one is meaningless, and `fields.*.w` has
     * to be nullable even though a photo without a box is. Left there, a text
     * field carrying a stray `w` reaches the renderer, which then has to guess
     * what the organizer meant — and guessing wrong is invisible until someone
     * looks at 500 printed cards.
     *
     * What is deliberately NOT checked: `x + w <= 100`. A photo that bleeds off
     * the card edge is legitimate print practice; the renderer clips to the
     * canvas.
     *
     * @param  array<int, mixed>  $fields
     */
    public static function check(Validator $validator, mixed $fields): void
    {
        if (! is_array($fields)) {
            return;
        }

        $seen = [];

        foreach ($fields as $i => $field) {
            if (! is_array($field)) {
                continue;
            }

            $key = $field['key'] ?? null;

            // One value per key: two `name` fields would print the same string
            // twice, and the second is always the one the organizer forgot.
            if (is_string($key) && isset($seen[$key])) {
                $validator->errors()->add("fields.$i.key", 'Field ini sudah dipakai.');
            }

            if (is_string($key)) {
                $seen[$key] = true;
            }

            if ($key === 'photo') {
                foreach (['w', 'h'] as $dimension) {
                    if (! isset($field[$dimension])) {
                        $validator->errors()->add("fields.$i.$dimension", 'Ukuran kotak foto wajib diisi.');
                    }
                }

                if (isset($field['size'])) {
                    $validator->errors()->add("fields.$i.size", 'Foto memakai lebar dan tinggi, bukan ukuran huruf.');
                }

                continue;
            }

            if ($key === null) {
                continue; // the wildcard rule already reported it
            }

            if (! isset($field['size'])) {
                $validator->errors()->add("fields.$i.size", 'Ukuran huruf wajib diisi.');
            }

            foreach (['w', 'h', 'fit', 'radius'] as $boxOnly) {
                if (isset($field[$boxOnly])) {
                    $validator->errors()->add("fields.$i.$boxOnly", 'Hanya field foto yang punya kotak.');
                }
            }
        }
    }
}
