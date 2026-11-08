<?php

namespace App\Http\Requests\Bib;

use Illuminate\Foundation\Http\FormRequest;

class GenerateBibsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The recipient list is always explicit, mirroring GenerateIdCardsRequest
     * and GenerateCertificatesRequest. "Select all" is a frontend affordance,
     * not a server mode: one code path, and the cap actually means something.
     *
     * Plain `entry_ids` rather than the `{type, id}` pairs the card request
     * takes: there is one pool here, so a discriminator would be a column with
     * one value that every reader has to branch on anyway.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'bib_template_id' => ['required', 'uuid'],

            'entry_ids' => ['required', 'array', 'min:1', 'max:'.(int) config('bib.max_recipients')],
            'entry_ids.*' => ['uuid'],
        ];
    }
}
