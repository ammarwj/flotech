<?php

namespace App\Http\Requests\Bib;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validation for an e-BIB template's payload, shared by the Store and Update
 * requests — the same reason IdCardTemplateRules, TeamPayloadRules and
 * EventCategoryRules exist.
 *
 * Here the sharing buys more than tidiness. Half the rules cannot be expressed
 * as a wildcard at all and live in an `after()` closure instead; two hand-kept
 * copies of that closure would be two versions of "a QR needs a box", and the
 * pair that drifted would let a template through that the renderer then has to
 * guess about.
 *
 * Shaped on IdCardTemplateRules but NOT shared with it, which is the same call
 * the migration makes about the table: there is no `photo` key here, so the box
 * branch exists for `qr` alone and `fit`/`radius` belong to nothing. A shared
 * class would have to be told which catalogue it was validating, and that
 * parameter is the branch this avoids.
 */
class BibTemplateRules
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

            // Between a card and a poster. Deliberately wide: the organizer
            // types two numbers, and the A5/A6 presets are a frontend
            // affordance that never reaches the API as an enum.
            'width_mm' => [...$required, 'numeric', 'between:20,400'],
            'height_mm' => [...$required, 'numeric', 'between:20,400'],

            // The catalogue holds five keys and each may appear once, so the
            // cap is here only so a runaway client cannot make the renderer
            // loop — the duplicate check below is what actually bounds it.
            'fields' => [...$required, 'array', 'min:1', 'max:12'],

            // Only keys the renderer knows how to fill, so a template can never
            // place a field that would print blank.
            'fields.*.key' => ['required', 'string', Rule::in(array_keys((array) config('bib.fields')))],
            'fields.*.x' => ['required', 'numeric', 'between:0,100'],
            'fields.*.y' => ['required', 'numeric', 'between:0,100'],

            // Text only. `size` is millimetres — see config/bib.php. The range
            // runs higher than the card twin's 40 on purpose: a race number is
            // the one thing on this sheet meant to be read from across a finish
            // line, and 60mm of it is ordinary.
            'fields.*.size' => ['nullable', 'numeric', 'between:1,120'],
            'fields.*.color' => ['nullable', 'string', 'max:9'],
            'fields.*.align' => ['nullable', Rule::in(['left', 'center', 'right'])],
            'fields.*.bold' => ['nullable', 'boolean'],
            'fields.*.uppercase' => ['nullable', 'boolean'],
            'fields.*.wrap' => ['nullable', 'numeric', 'between:10,100'],

            // The one box (`qr`).
            'fields.*.w' => ['nullable', 'numeric', 'between:1,100'],
            'fields.*.h' => ['nullable', 'numeric', 'between:1,100'],
        ];
    }

    /**
     * The rules a flat wildcard cannot state.
     *
     * Two field shapes share one array, so `fields.*.size` has to be nullable
     * even though a text field without one is meaningless, and `fields.*.w` has
     * to be nullable even though a QR without a box is. Left there, a text
     * field carrying a stray `w` reaches the renderer, which then has to guess
     * what the organizer meant — and guessing wrong is invisible until someone
     * looks at 500 printed sheets.
     *
     * `qr` is a box rather than a text field, and the pairing is load-bearing in
     * both directions: fall it through to the text branch and every QR is
     * refused for having no `size` and for carrying `w`/`h`.
     *
     * `fit` and `radius` are refused for ANY key here, not just for `qr` as in
     * the card twin — there is no `photo` in this catalogue for them to belong
     * to. The reasons they are refused rather than ignored still hold: `contain`
     * means nothing on something already square, and rounding the corners eats
     * the finder patterns a scanner locates the code by, which looks fine on
     * screen and fails in a marshal's hand.
     *
     * What is deliberately NOT checked: `x + w <= 100`. A QR that bleeds off
     * the sheet edge is legitimate print practice; the renderer clips to the
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

            // Neither shape takes these, so they are refused for every key
            // rather than for one — see the docblock.
            foreach (['fit', 'radius'] as $unsupported) {
                if (isset($field[$unsupported])) {
                    $validator->errors()->add(
                        "fields.$i.$unsupported",
                        'Field e-BIB tidak punya pilihan ini. QR selalu digambar penuh dan bersudut tajam — sudut membulat memakan pola pencari yang dibaca pemindai.',
                    );
                }
            }

            if ($key === 'qr') {
                foreach (['w', 'h'] as $dimension) {
                    if (! isset($field[$dimension])) {
                        $validator->errors()->add("fields.$i.$dimension", 'Ukuran kotak QR wajib diisi.');
                    }
                }

                if (isset($field['size'])) {
                    $validator->errors()->add(
                        "fields.$i.size",
                        'QR memakai lebar dan tinggi, bukan ukuran huruf.',
                    );
                }

                continue;
            }

            if ($key === null) {
                continue; // the wildcard rule already reported it
            }

            if (! isset($field['size'])) {
                $validator->errors()->add("fields.$i.size", 'Ukuran huruf wajib diisi.');
            }

            foreach (['w', 'h'] as $boxOnly) {
                if (isset($field[$boxOnly])) {
                    $validator->errors()->add("fields.$i.$boxOnly", 'Hanya field QR yang punya kotak.');
                }
            }
        }
    }
}
